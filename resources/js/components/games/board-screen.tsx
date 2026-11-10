import { Link } from '@inertiajs/react';
import {
    CloudOff,
    Maximize,
    Pause,
    Play,
    SkipForward,
    Smartphone,
    Square,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
import { BoardPrompt } from '@/components/games/board-prompt';
import { BoardResult } from '@/components/games/board-result';
import { BoardStudents } from '@/components/games/board-students';
import type { GuestBoardActionsProps } from '@/components/games/guest-board-actions';
import { GuestBoardActions } from '@/components/games/guest-board-actions';
import { PairingCard } from '@/components/games/pairing-card';
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
import { useFullscreen } from '@/hooks/use-fullscreen';
import { useTranslation } from '@/hooks/use-translation';
import { useWakeLock } from '@/hooks/use-wake-lock';
import { show } from '@/routes/classes';
import { board as boardRoute } from '@/routes/games';
import type { BoardProps } from '@/types';

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
 * inside the click, as browsers require.
 *
 * The teacher's phone drives it over the game's channel (`live.ts`). On a
 * teacher board the pairing card shows the code until a phone says hello,
 * «Pultu qoş» shows it again; a guest board was paired when the phone
 * claimed it, so it has no pairing card. The footer says when the phone
 * has gone quiet.
 *
 * Two board modes, one board: a teacher board (`games/board`) and a guest
 * board (`lovhe`) differ only in `auth` and in `guest`'s parts.
 */
export function BoardScreen({
    game,
    schoolClass,
    pack,
    students,
    auth,
    guest,
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
    const { isFullscreen, enter } = useFullscreen();

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
    const background = colours.background;

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
                        background,
                        sounds,
                        backdrop,
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
        background,
        sounds,
        backdrop,
        figures,
        pictures,
    ]);

    // The link to the phone: Echo loads on this page only. A game that
    // was already over when the page opened has no channel to join.
    const [link, setLink] = useState<LiveBoard | null>(null);
    const liveView = useLiveView(link);
    const open = game.status === 'live' || game.status === 'paused';

    useEffect(() => {
        if (controller === null || !open) {
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
        });

        setLink(created);

        return () => {
            created.dispose();
            echo.disconnect();
        };
    }, [controller, auth, game.id, open]);

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
        enter();
        stage.current?.unlockAudio();
        controller?.start();
    };

    const playing = view.phase === 'playing';
    const live = playing && view.status === 'live';
    const paused = playing && view.status === 'paused';

    return (
        <>
            <main
                className="fixed inset-0 flex touch-manipulation flex-col overflow-hidden select-none"
                style={{ backgroundColor: background, color: colours.text }}
            >
                <header className="flex items-center gap-6 px-6 pt-5 pb-2 xl:px-10 xl:pt-8">
                    <BoardPrompt
                        round={playing ? view.round : null}
                        accent={colours.accent}
                    />

                    <div className="flex shrink-0 flex-col items-end gap-3">
                        {playing && view.round !== null && (
                            <p className="text-lg font-semibold tabular-nums">
                                {t('games.board.round', {
                                    index: view.round.index + 1,
                                    total: view.round.total,
                                })}
                            </p>
                        )}
                        <div className="flex flex-wrap justify-end gap-2">
                            {guest === undefined &&
                                link !== null &&
                                playing &&
                                !pairingShown && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="lg"
                                        onClick={() => link.showPairing()}
                                    >
                                        <Smartphone />
                                        {t('games.board.pairing.open')}
                                    </Button>
                                )}
                            {!isFullscreen && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="lg"
                                    onClick={enter}
                                >
                                    <Maximize />
                                    {t('games.board.fullscreen')}
                                </Button>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="lg"
                                disabled={!live}
                                onClick={() => controller?.skip()}
                            >
                                <SkipForward />
                                {t('games.board.skip')}
                            </Button>
                            {paused ? (
                                <Button
                                    type="button"
                                    size="lg"
                                    disabled={view.busy}
                                    onClick={() => void controller?.resume()}
                                >
                                    <Play />
                                    {t('games.board.resume')}
                                </Button>
                            ) : (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="lg"
                                    disabled={!live || view.busy}
                                    onClick={() => void controller?.pause()}
                                >
                                    <Pause />
                                    {t('games.board.pause')}
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

                    {playing && pairingShown && (
                        <div className="absolute right-6 bottom-4 z-30 xl:right-10">
                            <PairingCard
                                compact
                                pairing={pairing.pairing}
                                failed={pairing.failed}
                                onClose={() => link?.hidePairing()}
                            />
                        </div>
                    )}

                    {paused && (
                        <Curtain>
                            <p className="text-6xl font-bold">
                                {t('games.board.paused')}
                            </p>
                            <Button
                                type="button"
                                size="lg"
                                className="h-16 px-10 text-2xl"
                                disabled={view.busy}
                                onClick={() => void controller?.resume()}
                            >
                                <Play className="size-7" />
                                {t('games.board.resume')}
                            </Button>
                        </Curtain>
                    )}
                </div>

                <footer className="flex items-center justify-between gap-4 px-6 pt-2 pb-5 xl:px-10 xl:pb-8">
                    <BoardStudents
                        students={students}
                        studentId={view.studentId}
                        accent={colours.accent}
                        disabled={view.phase === 'replaced'}
                        onChoose={(id) => controller?.setStudent(id)}
                        onNext={() => controller?.nextStudent()}
                    />

                    {link !== null && liveView.remote !== 'none' && (
                        <RemoteStatus remote={liveView.remote} />
                    )}

                    {view.pending > 0 && view.retrying && (
                        <p
                            className="flex items-center gap-3 rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-2 text-amber-900"
                            role="status"
                        >
                            <CloudOff className="size-7 shrink-0" aria-hidden />
                            <span>
                                <span className="block text-xl font-bold tabular-nums">
                                    {t('games.board.unsaved', {
                                        count: view.pending,
                                    })}
                                </span>
                                <span className="block text-sm">
                                    {t('games.board.unsaved_hint')}
                                </span>
                            </span>
                        </p>
                    )}
                </footer>

                {(view.phase === 'loading' || view.phase === 'ready') && (
                    <Curtain solid>
                        <div className="flex flex-col items-center gap-2 text-center">
                            <h1 className="text-5xl font-bold">{pack.title}</h1>
                            <p className="text-2xl">
                                {t('games.board.class', {
                                    name: schoolClass.name,
                                })}
                                {' · '}
                                {t('games.board.rounds', {
                                    count: game.rounds,
                                })}
                            </p>
                        </div>
                        {stageState === 'failed' ? (
                            <p className="text-xl text-red-700">
                                {t('games.board.stage_failed')}
                            </p>
                        ) : view.phase === 'ready' && stageState === 'ready' ? (
                            <Button
                                type="button"
                                size="lg"
                                className="h-20 rounded-2xl px-14 text-3xl"
                                style={{ backgroundColor: colours.accent }}
                                onClick={begin}
                                autoFocus
                            >
                                <Play className="size-8" />
                                {view.resumed
                                    ? t('games.board.continue')
                                    : t('games.board.start')}
                            </Button>
                        ) : (
                            <p
                                className="flex items-center gap-3 text-xl"
                                role="status"
                            >
                                <Spinner className="size-6" />
                                {view.notice === 'load_failed'
                                    ? t('games.board.notices.load_failed')
                                    : t('games.board.loading')}
                            </p>
                        )}
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

                {view.phase === 'finished' && (
                    <Curtain solid>
                        <BoardResult
                            classId={schoolClass.id}
                            points={view.points}
                            tally={view.tally}
                            pending={view.pending}
                            accent={colours.accent}
                            actions={
                                guest === undefined ? undefined : (
                                    <div className="flex flex-col items-center gap-6">
                                        <p
                                            className="flex items-center gap-3 text-xl text-neutral-600"
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
                            <h1 className="text-4xl font-bold">
                                {t('games.board.replaced.title')}
                            </h1>
                            <p className="text-xl text-neutral-600">
                                {t('games.board.replaced.description')}
                            </p>
                        </div>
                        {guest === undefined ? (
                            <div className="flex flex-wrap justify-center gap-3">
                                <Button asChild size="lg" variant="outline">
                                    <Link href={show(schoolClass.id)}>
                                        {t('games.board.back_to_class')}
                                    </Link>
                                </Button>
                                <Button asChild size="lg">
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
                        <p className="flex-1 text-lg">
                            {t(`games.board.notices.${view.notice}`)}
                        </p>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
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
                    disabled={disabled}
                >
                    <Square />
                    {t('games.board.end')}
                </Button>
            </DialogTrigger>
            <DialogContent>
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
                        <Button type="button" variant="outline">
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="destructive"
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

/** The phone: there, or gone quiet for 10 s (it keeps playing either way). */
function RemoteStatus({ remote }: { remote: 'connected' | 'lost' }) {
    const { t } = useTranslation();

    return remote === 'lost' ? (
        <p
            className="flex items-center gap-2 rounded-2xl border-2 border-amber-400 bg-amber-50 px-4 py-2 text-lg font-semibold text-amber-900"
            role="status"
        >
            <Smartphone className="size-6 shrink-0" aria-hidden />
            {t('games.board.remote.lost')}
        </p>
    ) : (
        <p
            className="flex items-center gap-2 text-base text-neutral-600"
            role="status"
        >
            <Smartphone className="size-5 shrink-0" aria-hidden />
            {t('games.board.remote.connected')}
        </p>
    );
}

/** A layer over the board: the start, the pause, the result. */
function Curtain({
    solid = false,
    children,
}: {
    solid?: boolean;
    children: ReactNode;
}) {
    return (
        <div
            className={
                solid
                    ? 'absolute inset-0 z-20 flex flex-col items-center justify-center gap-10 bg-white/90 p-6 backdrop-blur-sm'
                    : 'absolute inset-0 z-10 flex flex-col items-center justify-center gap-8 bg-white/80 p-6 backdrop-blur-sm'
            }
        >
            {children}
        </div>
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
