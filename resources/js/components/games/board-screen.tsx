import { Link } from '@inertiajs/react';
import {
    CloudOff,
    Maximize,
    Pause,
    Play,
    RotateCcw,
    SkipForward,
    Smartphone,
    Square,
    VolumeOff,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { BoardChild } from '@/components/games/board-child';
import { BoardPrompt } from '@/components/games/board-prompt';
import { BoardResult } from '@/components/games/board-result';
import { BoardStudents } from '@/components/games/board-students';
import type { GuestBoardActionsProps } from '@/components/games/guest-board-actions';
import { GuestBoardActions } from '@/components/games/guest-board-actions';
import { PairingCard } from '@/components/games/pairing-card';
import { StudentGrid, studentAvatars } from '@/components/games/student-grid';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import type { BoardView, TabChannel } from '@/game/board';
import { BoardController } from '@/game/board';
import { BoardBridge } from '@/game/bridge';
import { Mechanic } from '@/game/contract';
import { boardMute } from '@/game/mute';
import type { Pairing } from '@/game/contract';
import type { BoardAuth } from '@/game/http';
import { boardHttp, issueJoinCode } from '@/game/http';
import type { LiveBoardView } from '@/game/live';
import { LiveBoard } from '@/game/live';
import type { StorageLike } from '@/game/outbox';
import { boardColours } from '@/game/palette';
import { connectEcho, gameChannel } from '@/game/socket';
import type { Stage } from '@/game/stage';
import { forceLightTheme } from '@/hooks/use-appearance';
import { lockLandscape, useFullscreen } from '@/hooks/use-fullscreen';
import { useIsMobile } from '@/hooks/use-mobile';
import { useTranslation } from '@/hooks/use-translation';
import { useWakeLock } from '@/hooks/use-wake-lock';
import {
    asksWhoPlays,
    pairingOnLoad,
    playersHome,
    playersLabel,
} from '@/lib/game-players';
import { cn } from '@/lib/utils';
import { board as boardRoute } from '@/routes/games';
import type { BoardProps, BoardStudent } from '@/types';

/**
 * A guest board's own parts on the board (`/lovhe`, §4): whose board it is,
 * «Mənim deyil — yeni kod» and «Lövhəni bağla» instead of the teacher's
 * links, and «Burada aç» that claims the game again in this tab.
 */
export type GuestBoardChrome = GuestBoardActionsProps & {
    onReopen: () => void;
    /** This board's controller, so the page can flush its outbox before the next game or closing. */
    onController: (controller: BoardController | null) => void;
};

type Props = BoardProps & {
    /** The teacher's session (teacher board) or the guest board's bearer; one object per board. */
    auth: BoardAuth;
    guest?: GuestBoardChrome;
    /**
     * The teacher's choice in Settings → Oyun (teacher board only; a guest
     * board always has it). Off: no channel, no pairing card, no «Pultu
     * qoş» — the board is played on its own screen.
     */
    phoneRemote?: boolean;
};

/**
 * The board on the projector (docs/architecture.md §4): no app shell,
 * always light, coloured by the pack. React owns everything around the
 * canvas — the prompt, who is at the board, skip / pause / end, the
 * "not saved yet" count and the result; Phaser (loaded only here, on
 * demand) owns the canvas. The board controller decides everything and
 * both sides render it.
 *
 * «Başla» enters full screen, unlocks audio and takes a wake lock, all
 * inside the click, as browsers require. In a class game with students it
 * first asks «Kim oynayır?»: the tap on a choice is that click (§4 "Board
 * without a phone"). «Səssiz» silences the board on this device, before
 * «Başla» too (§4 "Board in the browser"). The page around this board
 * gives the screen back when it is left (`useReleaseScreenOnLeave`).
 *
 * The teacher's phone drives it over the game's channel (`live.ts`). On a
 * teacher board the pairing card shows the code until a phone says hello,
 * «Pultu qoş» shows it again; a guest board was paired when the phone
 * claimed it, so it has no pairing card. The footer says when the phone
 * has gone quiet. A teacher who turned the phone remote off gets a board
 * with no channel at all, like one without Reverb.
 *
 * A home game (§4 "At home", ADR 011) is played by the child touching the
 * screen: the child stands where the class's grid would, the pairing card
 * waits for «Pultu qoş» (on the start screen too), and the result adds
 * this game's stars to the child's total.
 *
 * Two board modes, one board: a teacher board (`games/board`) and a guest
 * board (`lovhe`) differ only in `auth` and in `guest`'s parts.
 *
 * From `lg` up this is the projector's layout; below it the same parts
 * shrink around the 16:9 canvas for a phone or a tablet (docs/frontend.md
 * §6): icon buttons, the pairing card in a dialog during play, and a hint
 * to turn a phone held upright.
 */
export function BoardScreen({
    game,
    schoolClass,
    child,
    pack,
    students,
    voice,
    auth,
    guest,
    phoneRemote = true,
}: Props) {
    const { t } = useTranslation();
    const colours = boardColours(pack.style.palette);
    const [bridge] = useState(() => new BoardBridge());
    const [controller, setController] = useState<BoardController | null>(null);
    const view = useBoardView(controller);
    const host = useRef<HTMLDivElement>(null);
    const stage = useRef<Stage | null>(null);
    const [stageState, setStageState] = useState<
        'loading' | 'ready' | 'failed'
    >('loading');
    const fullscreen = useFullscreen();
    const small = useIsMobile();
    const label = playersLabel({ schoolClass, child });
    const home = playersHome({ schoolClass, child });
    const pairsOnLoad = pairingOnLoad({ schoolClass, child });
    const asksWho = asksWhoPlays({ schoolClass, child }, students);
    const [asking, setAsking] = useState(false);
    // The server renders sound on; the device's choice follows at once.
    const muted = useSyncExternalStore(
        boardMute.subscribe,
        boardMute.get,
        () => false,
    );

    useEffect(() => forceLightTheme(), []);

    // The controller: flush, claim, load. Deferred a tick so React's
    // development double mount never claims the board twice.
    useEffect(() => {
        const created = new BoardController({
            gameId: game.id,
            status: game.status,
            epoch: game.board_epoch,
            studentIds: students.map((student) => student.id),
            points: Array.isArray(game.points) ? {} : game.points,
            http: boardHttp(game.id, auth),
            bridge,
            storage: sessionStore(),
            channel: tabChannel(),
        });
        const handle = window.setTimeout(() => {
            setController(created);
            void created.load();
        }, 0);

        return () => {
            window.clearTimeout(handle);
            created.dispose();
        };
    }, [
        bridge,
        auth,
        game.id,
        game.status,
        game.board_epoch,
        game.points,
        students,
    ]);

    // A guest board's page flushes this board before the next game.
    const onController = guest?.onController;

    useEffect(() => {
        if (onController === undefined || controller === null) {
            return;
        }

        onController(controller);

        return () => onController(null);
    }, [controller, onController]);

    // The canvas: Phaser is imported here and nowhere else.
    const {
        item: itemStyle,
        slot: slotStyle,
        palette,
        sounds,
        backdrop,
        figures,
    } = pack.style;
    const { pictures } = pack;

    useEffect(() => {
        const parent = host.current;
        const mechanic = Mechanic.safeParse(game.mechanic);

        if (parent === null || !mechanic.success) {
            setStageState('failed');

            return;
        }

        let cancelled = false;
        let mounted: Stage | null = null;
        const handle = window.setTimeout(() => {
            import('@/game/stage')
                .then(({ mountStage }) =>
                    mountStage({
                        parent,
                        bridge,
                        mechanic: mechanic.data,
                        itemStyle,
                        slotStyle,
                        palette,
                        sounds,
                        voice,
                        figures,
                        pictures,
                    }),
                )
                .then((ready) => {
                    if (cancelled) {
                        ready.destroy();

                        return;
                    }

                    mounted = ready;
                    stage.current = ready;
                    // The stage mounts after the page: silent from its start.
                    ready.setMuted(boardMute.get());
                    setStageState('ready');
                })
                .catch(() => {
                    if (!cancelled) {
                        setStageState('failed');
                    }
                });
        }, 0);

        return () => {
            cancelled = true;
            window.clearTimeout(handle);
            mounted?.destroy();
            stage.current = null;
        };
    }, [
        bridge,
        game.mechanic,
        itemStyle,
        slotStyle,
        palette,
        sounds,
        voice,
        figures,
        pictures,
    ]);

    // A toggle while the stage is loading is applied once it is ready.
    useEffect(() => {
        stage.current?.setMuted(muted);
    }, [muted, stageState]);

    const toggleMuted = () => boardMute.set(!muted);

    // The link to the phone: Echo loads on this page only. A game that
    // was already over when the page opened has no channel to join, and a
    // teacher who turned the phone remote off wants none.
    const [link, setLink] = useState<LiveBoard | null>(null);
    const liveView = useLiveView(link);
    const open = game.status === 'live' || game.status === 'paused';

    useEffect(() => {
        if (controller === null || !open || !phoneRemote) {
            return;
        }

        let echo: ReturnType<typeof connectEcho>;

        try {
            echo = connectEcho(auth);
        } catch {
            // No Reverb configured: the board plays without a phone.
            return;
        }

        const created = new LiveBoard({
            channel: gameChannel(echo, game.id),
            board: controller,
            pairing: pairsOnLoad,
        });

        setLink(created);

        return () => {
            created.dispose();
            echo.disconnect();
        };
    }, [controller, auth, game.id, open, phoneRemote, pairsOnLoad]);

    const pairingShown =
        guest === undefined &&
        link !== null &&
        liveView.pairing &&
        (view.phase === 'ready' || view.phase === 'playing');
    const pairing = useJoinCode(game.id, pairingShown);

    useWakeLock(view.phase === 'playing');

    useEffect(() => {
        if (controller === null) {
            return;
        }

        const online = () => controller.online();

        window.addEventListener('online', online);

        return () => window.removeEventListener('online', online);
    }, [controller]);

    // Closing or reloading the tab while answers wait would leave them in
    // this tab only: the browser asks first.
    const unsaved = view.pending > 0;

    useEffect(() => {
        if (!unsaved) {
            return;
        }

        const warn = (event: BeforeUnloadEvent) => {
            event.preventDefault();
        };

        window.addEventListener('beforeunload', warn);

        return () => window.removeEventListener('beforeunload', warn);
    }, [unsaved]);

    const begin = () => {
        void fullscreen.enter().then(lockLandscape);
        stage.current?.unlockAudio();
        controller?.start();
    };

    // «Başla» / «Davam et»: a class with students says who plays first.
    const pressStart = () => (asksWho ? setAsking(true) : begin());

    // The tap on a choice is the gesture `begin()` needs.
    const chooseAndBegin = (studentId: string | null) => {
        controller?.setStudent(studentId);
        setAsking(false);
        begin();
    };

    const playing = view.phase === 'playing';
    const live = playing && view.status === 'live';
    const paused = playing && view.status === 'paused';

    return (
        <>
            <main
                className="fixed inset-0 flex touch-manipulation flex-col overflow-hidden select-none"
                style={{
                    backgroundColor: colours.background,
                    color: colours.text,
                    // The pack's scene fills the screen behind the canvas,
                    // which is transparent (architecture §8 "Backdrops").
                    ...(backdrop !== null && {
                        backgroundImage: `url("${backdrop}")`,
                        backgroundPosition: 'center bottom',
                        backgroundSize: 'cover',
                        backgroundRepeat: 'no-repeat',
                    }),
                }}
            >
                <header className="flex items-center gap-6 px-6 pt-5 pb-2 max-lg:gap-3 max-lg:px-3 max-lg:pt-2 max-lg:pb-1 max-sm:flex-col max-sm:items-stretch max-sm:gap-2 xl:px-10 xl:pt-8">
                    <BoardPrompt
                        round={playing ? view.round : null}
                        accent={colours.accent}
                        onRepeat={
                            voice.prompt && !muted
                                ? () => bridge.repeatPrompt()
                                : null
                        }
                    />

                    <div className="flex shrink-0 flex-col items-end gap-3 max-lg:flex-row max-lg:items-center max-lg:gap-2 max-sm:flex-wrap max-sm:justify-end">
                        {playing && view.round !== null && (
                            <p className="text-lg font-semibold tabular-nums max-lg:mr-auto max-lg:text-sm max-lg:whitespace-nowrap">
                                {t('games.board.round', {
                                    index: view.round.index + 1,
                                    total: view.round.total,
                                })}
                            </p>
                        )}
                        <div className="flex flex-wrap justify-end gap-2 max-lg:flex-nowrap max-lg:gap-1">
                            {guest === undefined &&
                                link !== null &&
                                playing &&
                                !pairingShown && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="lg"
                                        className={ICON_BELOW_LG}
                                        onClick={() => link.showPairing()}
                                    >
                                        <Smartphone />
                                        <Label>
                                            {t('games.board.pairing.open')}
                                        </Label>
                                    </Button>
                                )}
                            {fullscreen.isSupported &&
                                !fullscreen.isFullscreen && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="lg"
                                        className={ICON_BELOW_LG}
                                        onClick={() =>
                                            void fullscreen
                                                .enter()
                                                .then(lockLandscape)
                                        }
                                    >
                                        <Maximize />
                                        <Label>
                                            {t('games.board.fullscreen')}
                                        </Label>
                                    </Button>
                                )}
                            <MuteToggle
                                muted={muted}
                                onToggle={toggleMuted}
                                header
                            />
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                className={ICON_BELOW_LG}
                                disabled={!live}
                                onClick={() => controller?.skip()}
                            >
                                <SkipForward />
                                <Label>{t('games.board.skip')}</Label>
                            </Button>
                            {paused ? (
                                <Button
                                    type="button"
                                    size="lg"
                                    className={ICON_BELOW_LG}
                                    disabled={view.busy}
                                    onClick={() => void controller?.resume()}
                                >
                                    <Play />
                                    <Label>{t('games.board.resume')}</Label>
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="lg"
                                    className={ICON_BELOW_LG}
                                    disabled={!live || view.busy}
                                    onClick={() => void controller?.pause()}
                                >
                                    <Pause />
                                    <Label>{t('games.board.pause')}</Label>
                                </Button>
                            )}
                            <EndGame
                                disabled={!playing || view.busy}
                                onEnd={() => void controller?.end()}
                            />
                        </div>
                    </div>
                </header>

                <div className="relative min-h-0 flex-1">
                    <div ref={host} className="absolute inset-0" />

                    {playing && pairingShown && !small && (
                        <div className="absolute right-6 bottom-4 z-30 xl:right-10">
                            <PairingCard
                                compact
                                pairing={pairing.pairing}
                                failed={pairing.failed}
                                onClose={() => link?.hidePairing()}
                            />
                        </div>
                    )}

                    {playing && (
                        <RotateHint className="absolute inset-x-0 bottom-2 justify-center" />
                    )}

                    {paused && (
                        <Curtain>
                            <p className="text-6xl font-bold max-lg:text-4xl">
                                {t('games.board.paused')}
                            </p>
                            <Button
                                type="button"
                                size="lg"
                                className="h-16 px-10 text-2xl max-lg:h-14 max-lg:px-8 max-lg:text-xl"
                                disabled={view.busy}
                                onClick={() => void controller?.resume()}
                            >
                                <Play className="size-7" />
                                {t('games.board.resume')}
                            </Button>
                        </Curtain>
                    )}
                </div>

                {small && (
                    <Dialog
                        open={playing && pairingShown}
                        onOpenChange={(opened) => {
                            if (!opened) {
                                link?.hidePairing();
                            }
                        }}
                    >
                        <DialogContent
                            showCloseButton={false}
                            aria-describedby={undefined}
                            className="border-0 bg-transparent p-0 shadow-none sm:w-auto sm:max-w-[calc(100%-2rem)]"
                        >
                            <DialogTitle className="sr-only">
                                {t('games.board.pairing.title')}
                            </DialogTitle>
                            <PairingCard
                                pairing={pairing.pairing}
                                failed={pairing.failed}
                                onClose={() => link?.hidePairing()}
                            />
                        </DialogContent>
                    </Dialog>
                )}

                <footer className="flex items-center justify-between gap-4 px-6 pt-2 pb-5 max-lg:gap-2 max-lg:px-3 max-lg:pt-1 max-lg:pb-2 max-sm:flex-wrap xl:px-10 xl:pb-8">
                    {child !== null ? (
                        <BoardChild child={child} accent={colours.accent} />
                    ) : (
                        <BoardStudents
                            students={students}
                            studentId={view.studentId}
                            accent={colours.accent}
                            disabled={view.phase === 'replaced'}
                            onChoose={(id) => controller?.setStudent(id)}
                            onNext={() => controller?.nextStudent()}
                        />
                    )}

                    {link !== null && liveView.remote !== 'none' && (
                        <RemoteStatus remote={liveView.remote} />
                    )}

                    {view.pending > 0 && view.retrying && (
                        <p
                            className="flex min-w-0 items-center gap-3 rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-2 text-amber-900 max-lg:gap-2 max-lg:px-2 max-lg:py-1"
                            role="status"
                        >
                            <CloudOff
                                className="size-7 shrink-0 max-lg:size-5"
                                aria-hidden
                            />
                            <span className="min-w-0">
                                <span className="block text-xl font-bold tabular-nums max-lg:text-base">
                                    {t('games.board.unsaved', {
                                        count: view.pending,
                                    })}
                                </span>
                                <span className="block text-sm max-lg:text-xs">
                                    {t('games.board.unsaved_hint')}
                                </span>
                            </span>
                        </p>
                    )}
                </footer>

                {(view.phase === 'loading' || view.phase === 'ready') && (
                    <Curtain solid className="max-lg:landscape:flex-row">
                        <div className="flex min-w-0 flex-col items-center gap-10 max-lg:gap-5">
                            <div className="flex flex-col items-center gap-2 text-center">
                                {child !== null && (
                                    <StudentAvatar
                                        avatar={child.avatar.key}
                                        avatars={studentAvatars([child])}
                                        initial={child.display_name.slice(0, 1)}
                                        className="mb-2 size-28 text-4xl max-lg:mb-0 max-lg:size-16 max-lg:text-2xl max-lg:landscape:hidden"
                                    />
                                )}
                                <h1 className="text-5xl font-bold max-lg:text-3xl">
                                    {pack.title}
                                </h1>
                                <p className="text-2xl max-lg:text-lg">
                                    {t(label.key, label.values)}
                                    {' · '}
                                    {t('games.board.rounds', {
                                        count: game.rounds,
                                    })}
                                </p>
                            </div>
                            {stageState === 'failed' ? (
                                <p className="text-xl text-red-700 max-lg:text-base">
                                    {t('games.board.stage_failed')}
                                </p>
                            ) : view.phase === 'ready' &&
                              stageState === 'ready' ? (
                                <Button
                                    type="button"
                                    size="lg"
                                    className="h-20 rounded-2xl px-14 text-3xl max-lg:h-14 max-lg:px-8 max-lg:text-xl"
                                    style={{ backgroundColor: colours.accent }}
                                    onClick={pressStart}
                                    autoFocus
                                >
                                    <Play className="size-8 max-lg:size-6" />
                                    {view.resumed
                                        ? t('games.board.continue')
                                        : t('games.board.start')}
                                </Button>
                            ) : (
                                <p
                                    className="flex items-center gap-3 text-xl max-lg:text-base"
                                    role="status"
                                >
                                    <Spinner className="size-6" />
                                    {view.notice === 'load_failed'
                                        ? t('games.board.notices.load_failed')
                                        : t('games.board.loading')}
                                </p>
                            )}
                            {view.phase === 'ready' && (
                                <div className="flex flex-wrap justify-center gap-3 max-lg:gap-2">
                                    {guest === undefined &&
                                        link !== null &&
                                        !pairingShown && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="lg"
                                                className="max-lg:h-11"
                                                onClick={() =>
                                                    link.showPairing()
                                                }
                                                data-test="open-pairing"
                                            >
                                                <Smartphone />
                                                {t('games.board.pairing.open')}
                                            </Button>
                                        )}
                                    <MuteToggle
                                        muted={muted}
                                        onToggle={toggleMuted}
                                    />
                                </div>
                            )}
                            <RotateHint />
                        </div>
                        {view.phase === 'ready' && pairingShown && (
                            <PairingCard
                                pairing={pairing.pairing}
                                failed={pairing.failed}
                                onClose={() => link?.hidePairing()}
                            />
                        )}
                        {guest !== undefined && (
                            <GuestBoardActions {...guest} />
                        )}
                    </Curtain>
                )}

                {child === null && (
                    <WhoPlays
                        open={asking && view.phase === 'ready'}
                        students={students}
                        studentId={view.studentId}
                        accent={colours.accent}
                        onOpenChange={setAsking}
                        onChoose={chooseAndBegin}
                    />
                )}

                {view.phase === 'finished' && (
                    <Curtain solid>
                        <BoardResult
                            players={{ schoolClass, child }}
                            child={child}
                            points={view.points}
                            tally={view.tally}
                            pending={view.pending}
                            accent={colours.accent}
                            actions={
                                guest === undefined ? undefined : (
                                    <div className="flex flex-col items-center gap-6 max-lg:gap-3">
                                        <p
                                            className="flex items-center gap-3 text-xl text-neutral-600 max-lg:text-base"
                                            role="status"
                                        >
                                            <Spinner className="size-5" />
                                            {t('lovhe.next_game')}
                                        </p>
                                        <GuestBoardActions {...guest} />
                                    </div>
                                )
                            }
                        />
                    </Curtain>
                )}

                {view.phase === 'replaced' && (
                    <Curtain solid>
                        <div className="flex max-w-2xl flex-col items-center gap-4 text-center">
                            <h1 className="text-4xl font-bold max-lg:text-2xl">
                                {t('games.board.replaced.title')}
                            </h1>
                            <p className="text-xl text-neutral-600 max-lg:text-base">
                                {t('games.board.replaced.description')}
                            </p>
                        </div>
                        {guest === undefined ? (
                            <div className="flex flex-wrap justify-center gap-3">
                                <Button
                                    asChild
                                    size="lg"
                                    variant="outline"
                                    className="max-lg:h-11"
                                >
                                    <Link href={home.href}>
                                        {t(home.label)}
                                    </Link>
                                </Button>
                                <Button
                                    asChild
                                    size="lg"
                                    className="max-lg:h-11"
                                >
                                    {/* A full load: claiming the board again needs a fresh page. */}
                                    <a href={boardRoute.url(game.id)}>
                                        {t('games.board.replaced.open_here')}
                                    </a>
                                </Button>
                            </div>
                        ) : (
                            <Button
                                type="button"
                                size="lg"
                                className="max-lg:h-11"
                                onClick={guest.onReopen}
                            >
                                {t('games.board.replaced.open_here')}
                            </Button>
                        )}
                    </Curtain>
                )}

                {view.notice !== null && view.notice !== 'load_failed' && (
                    <div
                        className="absolute top-4 left-1/2 z-30 flex w-[min(40rem,calc(100%-2rem))] -translate-x-1/2 items-start gap-3 rounded-2xl border-2 border-amber-400 bg-amber-50 p-4 text-amber-900 shadow-lg"
                        role="alert"
                    >
                        <p className="flex-1 text-lg max-lg:text-base">
                            {t(`games.board.notices.${view.notice}`)}
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="max-lg:size-11"
                            onClick={() => controller?.dismissNotice()}
                            aria-label={t('common.close')}
                        >
                            <X />
                        </Button>
                    </div>
                )}
            </main>
        </>
    );
}

function EndGame({
    disabled,
    onEnd,
}: {
    disabled: boolean;
    onEnd: () => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    className={ICON_BELOW_LG}
                    disabled={disabled}
                >
                    <Square />
                    <Label>{t('games.board.end')}</Label>
                </Button>
            </DialogTrigger>
            <DialogContent showCloseButton={false}>
                <DialogHeader>
                    <DialogTitle>
                        {t('games.board.end_confirm.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('games.board.end_confirm.description')}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="outline"
                            className="max-lg:h-11"
                        >
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="destructive"
                            className="max-lg:h-11"
                            onClick={onEnd}
                        >
                            {t('games.board.end_confirm.confirm')}
                        </Button>
                    </DialogClose>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/**
 * «Kim oynayır?» (§4 "Board without a phone"): «Bütün sinif» or a student,
 * the current choice marked. A tap chooses and starts the game; closing
 * starts nothing.
 */
function WhoPlays({
    open,
    students,
    studentId,
    accent,
    onOpenChange,
    onChoose,
}: {
    open: boolean;
    students: BoardStudent[];
    studentId: string | null;
    accent: string;
    onOpenChange: (open: boolean) => void;
    onChoose: (studentId: string | null) => void;
}) {
    const { t } = useTranslation();

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90svh] overflow-y-auto sm:max-w-3xl"
                data-test="who-plays"
            >
                <DialogHeader>
                    <DialogTitle>
                        {t('games.board.who_plays.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('games.board.who_plays.description')}
                    </DialogDescription>
                </DialogHeader>
                <StudentGrid
                    students={students}
                    studentId={studentId}
                    accent={accent}
                    onChoose={onChoose}
                />
            </DialogContent>
        </Dialog>
    );
}

/**
 * The board's silent mode: one label and a crossed-out speaker in both
 * states, filled while silent, so it is never taken for the prompt's 🔊
 * (docs/frontend.md §4). In the header it is a 44px icon below `lg`.
 */
function MuteToggle({
    muted,
    onToggle,
    header = false,
}: {
    muted: boolean;
    onToggle: () => void;
    header?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Button
            type="button"
            variant={muted ? 'default' : 'outline'}
            size="lg"
            className={header ? ICON_BELOW_LG : 'max-lg:h-11'}
            aria-pressed={muted}
            onClick={onToggle}
            data-test="mute"
        >
            <VolumeOff />
            {header ? (
                <Label>{t('games.board.mute')}</Label>
            ) : (
                t('games.board.mute')
            )}
        </Button>
    );
}

/** The phone: there, or gone quiet for 10 s (it keeps playing either way). */
function RemoteStatus({ remote }: { remote: 'connected' | 'lost' }) {
    const { t } = useTranslation();

    return remote === 'lost' ? (
        <p
            className="flex min-w-0 items-center gap-2 rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-2 text-lg font-semibold text-amber-900 max-lg:px-2 max-lg:py-1 max-lg:text-sm"
            role="status"
        >
            <Smartphone className="size-6 shrink-0 max-lg:size-5" aria-hidden />
            {t('games.board.remote.lost')}
        </p>
    ) : (
        <p
            className="flex min-w-0 items-center gap-2 text-base text-neutral-600 max-lg:text-sm"
            role="status"
        >
            <Smartphone className="size-5 shrink-0" aria-hidden />
            <span className="max-sm:sr-only">
                {t('games.board.remote.connected')}
            </span>
        </p>
    );
}

/**
 * A layer over the board: the start, the pause, the result. One too tall
 * for a small screen scrolls inside itself, never the page, and keeps its
 * top reachable (`safe` centring).
 */
function Curtain({
    solid = false,
    className,
    children,
}: {
    solid?: boolean;
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                solid
                    ? 'absolute inset-0 z-20 flex flex-col items-center justify-center-safe gap-10 overflow-y-auto bg-white/90 p-6 backdrop-blur-sm'
                    : 'absolute inset-0 z-10 flex flex-col items-center justify-center-safe gap-8 overflow-y-auto bg-white/80 p-6 backdrop-blur-sm',
                'max-lg:gap-5 max-lg:p-3 [&>*]:shrink-0',
                className,
            )}
        >
            {children}
        </div>
    );
}

/**
 * Below `lg` the header's buttons are 44px icons; the label stays for
 * screen readers (docs/frontend.md §6). From `lg` nothing changes.
 */
const ICON_BELOW_LG =
    'max-lg:size-11 max-lg:has-[>svg]:px-0 max-lg:[&_svg:not([class*=size-])]:size-5';

function Label({ children }: { children: ReactNode }) {
    return <span className="max-lg:sr-only">{children}</span>;
}

/** A phone held upright: the 16:9 board is bigger sideways. */
function RotateHint({ className }: { className?: string }) {
    const { t } = useTranslation();

    return (
        <p
            className={cn(
                'pointer-events-none hidden items-center gap-2 text-center text-sm text-neutral-600 max-lg:portrait:flex',
                className,
            )}
            role="note"
        >
            <RotateCcw className="size-4 shrink-0" aria-hidden />
            {t('games.board.rotate_hint')}
        </p>
    );
}

const LOADING_VIEW: BoardView = {
    phase: 'loading',
    status: 'live',
    epoch: 0,
    round: null,
    progress: null,
    tally: null,
    resumed: false,
    pending: 0,
    retrying: false,
    points: 0,
    lastPoints: null,
    studentId: null,
    busy: false,
    notice: null,
};

const noSubscription = () => () => {};

function useBoardView(controller: BoardController | null): BoardView {
    return useSyncExternalStore(
        controller?.subscribe ?? noSubscription,
        controller?.getView ?? (() => LOADING_VIEW),
        () => LOADING_VIEW,
    );
}

const NO_LIVE_VIEW: LiveBoardView = {
    remote: 'none',
    pairing: false,
    socket: 'connecting',
};

function useLiveView(live: LiveBoard | null): LiveBoardView {
    return useSyncExternalStore(
        live?.subscribe ?? noSubscription,
        live?.getView ?? (() => NO_LIVE_VIEW),
        () => NO_LIVE_VIEW,
    );
}

/**
 * The pairing card's code while it is shown: asked for when the card
 * opens (the server keeps a code that is still valid), and again when it
 * expires; a failure retries after 5 s.
 */
function useJoinCode(
    gameId: string,
    wanted: boolean,
): { pairing: Pairing | null; failed: boolean } {
    const [pairing, setPairing] = useState<Pairing | null>(null);
    const [failed, setFailed] = useState(false);

    useEffect(() => {
        if (!wanted) {
            return;
        }

        let cancelled = false;
        let handle: number | undefined;

        const ask = async () => {
            const result = await issueJoinCode(gameId);

            if (cancelled) {
                return;
            }

            if (result.kind === 'ok') {
                setPairing(result.pairing);
                setFailed(false);
                const left = Date.parse(result.pairing.expires_at) - Date.now();

                handle = window.setTimeout(
                    () => void ask(),
                    Math.max(5000, left + 1000),
                );
            } else if (result.kind === 'failed') {
                setFailed(true);
                handle = window.setTimeout(() => void ask(), 5000);
            }
        };

        void ask();

        return () => {
            cancelled = true;
            window.clearTimeout(handle);
        };
    }, [gameId, wanted]);

    return { pairing, failed };
}

function sessionStore(): StorageLike | null {
    try {
        return window.sessionStorage;
    } catch {
        return null;
    }
}

/** Tabs of this browser tell each other when they claim a board. */
function tabChannel(): TabChannel | null {
    if (typeof BroadcastChannel === 'undefined') {
        return null;
    }

    const channel = new BroadcastChannel('elifba.board');

    return {
        post: (message) => channel.postMessage(message),
        listen: (listener) => {
            channel.onmessage = (event: MessageEvent<unknown>) =>
                listener(event.data);
        },
        close: () => channel.close(),
    };
}
