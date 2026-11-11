import { Form, Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    MonitorUp,
    MonitorX,
    Pause,
    Play,
    QrCode,
    SkipForward,
    Square,
    Star,
    Users,
    WifiOff,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useState, useSyncExternalStore } from 'react';
import { RemoteItems } from '@/components/games/remote-items';
import { RemoteQuestion } from '@/components/games/remote-question';
import { RemoteSlots } from '@/components/games/remote-slots';
import { BoardChild } from '@/components/games/board-child';
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
import type { ControlAction, Round, SessionStatus } from '@/game/contract';
import { boardHttp } from '@/game/http';
import type { LiveRemoteView } from '@/game/live';
import { ackStats, LiveRemote } from '@/game/live';
import { connectEcho, gameChannel } from '@/game/socket';
import { ulid } from '@/game/ulid';
import { useTranslation } from '@/hooks/use-translation';
import { useWakeLock } from '@/hooks/use-wake-lock';
import { cn } from '@/lib/utils';
import { pult } from '@/routes';
import { playersHome, playersLabel } from '@/lib/game-players';
import type { GamePlayers } from '@/lib/game-players';
import { destroy as closeBoard } from '@/routes/guest-boards';
import { edit as nextGame } from '@/routes/guest-boards/game';
import type { BoardStudent, RemoteProps } from '@/types';

/**
 * `/games/{game}/remote`, the teacher's phone (docs/architecture.md §4).
 * It writes nothing that counts: taps, who is at the board, pause, skip
 * and end go to the board as client events (`live.ts`), and the board
 * judges, plays and saves. The rounds come from `GET /games/{game}/state`
 * (the answer is marked for the teacher); where the board stands comes
 * from its `client-state`.
 *
 * One hand on a phone: the items and every control sit in the lower half.
 * When the board is gone, «Oyunu bitir» changes the status over HTTP
 * itself (§4, "Reconnects"). `?debug=1` shows `ack_ms`.
 *
 * A guest board (`guestBoard`) was paired by its claim, so the phone has no
 * pairing card to ask for; instead it closes the board, and after the game
 * sends the next one to it without a new code.
 *
 * In a home game (§4 "At home") the child is always the one at the board:
 * the phone shows the child where the class's grid would be, and sends no
 * `client-student`.
 */
export default function Remote({
    game,
    schoolClass,
    child,
    students,
    guestBoard,
}: RemoteProps) {
    const { t } = useTranslation();
    const label = playersLabel({ schoolClass, child });
    const { url } = usePage();
    const debug =
        new URLSearchParams(url.split('?')[1] ?? '').get('debug') === '1';
    const [link, setLink] = useState<LiveRemote | null>(null);
    const view = useRemoteView(link);
    const [endedHere, setEndedHere] = useState<SessionStatus | null>(null);
    const status = endedHere ?? view.status ?? game.status;
    const over = status === 'finished' || status === 'abandoned';
    const state = view.state;
    const rounds = useRounds(game.id, state?.round_id ?? null, !over);
    const round =
        state?.round_id === null || state === null
            ? null
            : (rounds?.find((r) => r.id === state.round_id) ?? null);

    useEffect(() => {
        if (game.status !== 'live' && game.status !== 'paused') {
            return;
        }

        let echo: ReturnType<typeof connectEcho>;

        try {
            echo = connectEcho();
        } catch {
            return;
        }

        const created = new LiveRemote({
            channel: gameChannel(echo, game.id),
            deviceId: deviceId(),
        });

        setLink(created);

        return () => {
            created.dispose();
            echo.disconnect();
        };
    }, [game.id, game.status]);

    useWakeLock(!over);

    const connected = view.socket === 'connected';
    const boardHere = connected && view.board === 'connected' && state !== null;
    const canTap = boardHere && status === 'live' && round !== null && !over;

    const tap = (itemId: string) => {
        if (round === null || link === null) {
            return;
        }

        if (link.tap(round.id, itemId) !== null) {
            navigator.vibrate?.(15);
        }
    };

    const control = (action: ControlAction) => link?.control(action);

    const endFromHere = async () => {
        const result = await boardHttp(game.id).status('finished');

        if (result.kind === 'failed') {
            return false;
        }

        setEndedHere(result.game.status);
        link?.statusChanged(result.game.status);

        return true;
    };

    return (
        <>
            <Head title={t('pult.title')} />

            <main className="flex min-h-svh flex-col bg-background text-foreground">
                <header className="flex items-center gap-2 border-b px-2 py-2">
                    <Button
                        asChild
                        variant="ghost"
                        size="icon"
                        className="size-11 shrink-0"
                    >
                        <Link
                            href={pult({ query: { list: 1 } })}
                            aria-label={t('pult.other_game')}
                        >
                            <ChevronLeft className="size-6" />
                        </Link>
                    </Button>
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-semibold">
                            {t(label.key, label.values)}
                        </p>
                        <p className="truncate text-sm text-muted-foreground">
                            {round !== null
                                ? t('games.board.round', {
                                      index: round.index + 1,
                                      total: round.total,
                                  })
                                : t(`games.mechanics.${game.mechanic}`)}
                            {' · '}
                            {t(`pult.status.${status}`)}
                        </p>
                    </div>
                    {state?.last_points != null && !over && (
                        <p className="flex shrink-0 items-center gap-1 text-sm font-semibold">
                            <Star
                                className="size-4 fill-amber-400 text-amber-500"
                                aria-hidden
                            />
                            {t('games.remote.last_points', {
                                count: state.last_points,
                            })}
                        </p>
                    )}
                    {guestBoard !== null ? (
                        <CloseGuestBoard boardId={guestBoard.id} icon />
                    ) : (
                        !over && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                className="size-11 shrink-0"
                                disabled={!boardHere}
                                onClick={() => control('pairing')}
                                aria-label={t('games.remote.show_code')}
                            >
                                <QrCode className="size-5" />
                            </Button>
                        )
                    )}
                </header>

                {over ? (
                    <Finished
                        players={{ schoolClass, child }}
                        guestBoardId={guestBoard?.id ?? null}
                    />
                ) : (
                    <>
                        <Banner
                            view={view}
                            status={status}
                            hasLink={link !== null}
                        />

                        {debug && <DebugStats samples={view.ackMs} />}

                        <section className="flex flex-1 flex-col justify-end gap-3 p-3">
                            {round !== null && (
                                <p className="text-center font-board text-xl">
                                    {round.prompt.text}
                                </p>
                            )}
                            {round !== null && round.mechanic === 'count' && (
                                <RemoteQuestion round={round} />
                            )}
                            {round !== null && round.slots > 0 && (
                                <RemoteSlots
                                    round={round}
                                    hitItemIds={state?.hit_item_ids ?? []}
                                />
                            )}
                            {round !== null ? (
                                <RemoteItems
                                    round={round}
                                    hitItemIds={state?.hit_item_ids ?? []}
                                    taps={view.taps}
                                    disabled={!canTap}
                                    onTap={tap}
                                />
                            ) : (
                                state?.round_id != null && (
                                    <p
                                        className="flex items-center justify-center gap-2 py-8 text-muted-foreground"
                                        role="status"
                                    >
                                        <Spinner />
                                        {t('games.remote.loading_rounds')}
                                    </p>
                                )
                            )}
                        </section>

                        <footer className="sticky bottom-0 flex flex-col gap-3 border-t bg-background p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]">
                            {child !== null ? (
                                <BoardChild child={child} />
                            ) : (
                                <RemoteStudents
                                    students={students}
                                    studentId={state?.student_id ?? null}
                                    disabled={!boardHere}
                                    onChoose={(id) => link?.setStudent(id)}
                                />
                            )}
                            <div className="grid grid-cols-3 gap-2">
                                {status === 'paused' ? (
                                    <ControlButton
                                        disabled={!boardHere}
                                        onClick={() => control('resume')}
                                        primary
                                    >
                                        <Play />
                                        {t('games.board.resume')}
                                    </ControlButton>
                                ) : (
                                    <ControlButton
                                        disabled={
                                            !boardHere ||
                                            state?.round_id == null
                                        }
                                        onClick={() => control('pause')}
                                    >
                                        <Pause />
                                        {t('games.board.pause')}
                                    </ControlButton>
                                )}
                                <ControlButton
                                    disabled={!canTap}
                                    onClick={() => control('skip')}
                                >
                                    <SkipForward />
                                    {t('games.board.skip')}
                                </ControlButton>
                                <EndGame
                                    boardHere={boardHere}
                                    onEnd={() => control('end')}
                                    onEndHere={endFromHere}
                                />
                            </div>
                        </footer>
                    </>
                )}
            </main>
        </>
    );
}

/** What stands between the teacher and the board right now, if anything. */
function Banner({
    view,
    status,
    hasLink,
}: {
    view: LiveRemoteView;
    status: SessionStatus;
    hasLink: boolean;
}) {
    const { t } = useTranslation();
    let tone: 'warn' | 'info' = 'info';
    let text: string | null = null;

    if (!hasLink || view.socket !== 'connected') {
        tone = 'warn';
        text = t('games.remote.banner.reconnecting');
    } else if (view.board === 'lost') {
        tone = 'warn';
        text = t('games.remote.banner.board_lost');
    } else if (view.board === 'waiting' || view.state === null) {
        text = t('games.remote.banner.board_waiting');
    } else if (status === 'paused') {
        text = t('games.board.paused');
    } else if (view.state.round_id === null) {
        text = t('games.remote.banner.press_start');
    }

    if (text === null) {
        return null;
    }

    return (
        <p
            className={cn(
                'mx-3 mt-3 flex items-center gap-2 rounded-xl border px-3 py-2 text-sm font-medium',
                tone === 'warn'
                    ? 'border-amber-400 bg-amber-50 text-amber-900 dark:bg-amber-950 dark:text-amber-100'
                    : 'border-border bg-muted text-foreground',
            )}
            role="status"
        >
            {tone === 'warn' ? (
                <WifiOff className="size-4 shrink-0" aria-hidden />
            ) : view.board === 'waiting' ? (
                <Spinner className="size-4 shrink-0" />
            ) : null}
            {text}
        </p>
    );
}

function DebugStats({ samples }: { samples: readonly number[] }) {
    const { t } = useTranslation();
    const stats = ackStats(samples);

    return (
        <p className="mx-3 mt-2 rounded-lg bg-muted px-3 py-1 font-mono text-xs tabular-nums">
            {t('games.remote.debug', {
                count: stats.count,
                median: stats.p50 ?? '–',
                tail: stats.p95 ?? '–',
            })}
        </p>
    );
}

/** Who is at the board: the current student, the class grid, and «Növbəti». */
function RemoteStudents({
    students,
    studentId,
    disabled,
    onChoose,
}: {
    students: BoardStudent[];
    studentId: string | null;
    disabled: boolean;
    onChoose: (studentId: string | null) => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const current = students.find((student) => student.id === studentId);
    const avatars = studentAvatars(students);

    const choose = (id: string | null) => {
        onChoose(id);
        setOpen(false);
    };

    // Class order; after the last, the first again (§4).
    const next = () => {
        if (students.length === 0) {
            return;
        }

        const at = students.findIndex((student) => student.id === studentId);

        onChoose(students[(at + 1) % students.length].id);
    };

    return (
        <div className="flex min-w-0 items-center gap-2">
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <button
                        type="button"
                        disabled={disabled}
                        className="flex min-h-16 min-w-0 flex-1 items-center gap-3 rounded-2xl border-2 bg-card px-3 py-2 text-left disabled:opacity-50"
                        aria-label={t('games.board.choose_student')}
                    >
                        {current === undefined ? (
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-full bg-muted">
                                <Users className="size-6" aria-hidden />
                            </span>
                        ) : (
                            <StudentAvatar
                                avatar={current.avatar.key}
                                avatars={avatars}
                                initial={current.display_name.slice(0, 1)}
                                className="size-11 text-lg"
                            />
                        )}
                        <span className="min-w-0">
                            <span className="block text-xs font-medium text-muted-foreground">
                                {t('games.board.at_board')}
                            </span>
                            <span className="block truncate font-board text-xl font-bold">
                                {current?.display_name ??
                                    t('games.board.whole_class')}
                            </span>
                        </span>
                    </button>
                </DialogTrigger>
                <DialogContent className="max-h-[90svh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {t('games.board.choose_student')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('games.board.choose_student_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <StudentGrid
                        students={students}
                        studentId={studentId}
                        onChoose={choose}
                    />
                </DialogContent>
            </Dialog>

            {students.length > 0 && (
                <Button
                    type="button"
                    variant="outline"
                    className="h-16 shrink-0 px-4 text-base"
                    disabled={disabled}
                    onClick={next}
                >
                    {t('games.board.next_student')}
                    <ChevronRight />
                </Button>
            )}
        </div>
    );
}

function ControlButton({
    disabled,
    onClick,
    primary = false,
    children,
}: {
    disabled: boolean;
    onClick: () => void;
    primary?: boolean;
    children: ReactNode;
}) {
    return (
        <Button
            type="button"
            variant={primary ? 'default' : 'outline'}
            className="h-16 flex-col gap-1 text-sm [&_svg]:size-5"
            disabled={disabled}
            onClick={onClick}
        >
            {children}
        </Button>
    );
}

/**
 * «Oyunu bitir», asked first. With the board there, the board ends the game
 * (it saves its answers first); without it, the phone changes the status
 * over HTTP and the board learns it on its next load or `session.status`.
 */
function EndGame({
    boardHere,
    onEnd,
    onEndHere,
}: {
    boardHere: boolean;
    onEnd: () => void;
    onEndHere: () => Promise<boolean>;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);

    const confirm = async () => {
        if (boardHere) {
            onEnd();
            setOpen(false);

            return;
        }

        setBusy(true);
        setFailed(false);
        const ended = await onEndHere();

        setBusy(false);

        if (ended) {
            setOpen(false);
        } else {
            setFailed(true);
        }
    };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setFailed(false);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    className="h-16 flex-col gap-1 text-sm [&_svg]:size-5"
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
                        {boardHere
                            ? t('games.board.end_confirm.description')
                            : t('games.remote.end_without_board')}
                    </DialogDescription>
                </DialogHeader>
                {failed && (
                    <p className="text-sm text-destructive" role="alert">
                        {t('games.remote.end_failed')}
                    </p>
                )}
                <DialogFooter>
                    <DialogClose asChild>
                        <Button type="button" variant="outline">
                            {t('common.cancel')}
                        </Button>
                    </DialogClose>
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={busy}
                        onClick={() => void confirm()}
                    >
                        {busy && <Spinner />}
                        {t('games.board.end_confirm.confirm')}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function Finished({
    players,
    guestBoardId,
}: {
    /** The class or the child the game was for. */
    players: GamePlayers;
    /** The guest board that showed the game: the next game goes to it. */
    guestBoardId: string | null;
}) {
    const { t } = useTranslation();
    const home = playersHome(players);

    return (
        <section className="flex flex-1 flex-col items-center justify-end gap-3 p-4 pb-8 text-center">
            <Star
                className="size-12 fill-amber-400 text-amber-500"
                aria-hidden
            />
            <h1 className="text-2xl font-semibold">
                {t('games.remote.finished')}
            </h1>
            <div className="mt-4 flex w-full flex-col gap-2">
                {guestBoardId !== null && (
                    <Button asChild className="h-14 text-base">
                        <Link
                            href={nextGame(guestBoardId)}
                            onHttpException={forgetGoneBoard}
                        >
                            <MonitorUp />
                            {t('games.remote.guest_board.next_game')}
                        </Link>
                    </Button>
                )}
                {guestBoardId !== null && (
                    <CloseGuestBoard boardId={guestBoardId} />
                )}
                <Button
                    asChild
                    variant={guestBoardId === null ? 'default' : 'outline'}
                    className="h-14 text-base"
                >
                    <Link href={pult({ query: { list: 1 } })}>
                        {t('pult.other_game')}
                    </Link>
                </Button>
                <Button asChild variant="outline" className="h-14 text-base">
                    <Link href={home.href}>{t(home.label)}</Link>
                </Button>
            </div>
        </section>
    );
}

/**
 * «Lövhəni bağla» from the phone (§4, "Guest board" step 4): the board's
 * bearer stops working at once, the game goes on here and can be sent to
 * another board.
 */
function CloseGuestBoard({
    boardId,
    icon = false,
}: {
    boardId: string;
    icon?: boolean;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {icon ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        className="size-11 shrink-0"
                        aria-label={t('games.remote.guest_board.close')}
                    >
                        <MonitorX className="size-5" />
                    </Button>
                ) : (
                    <Button
                        type="button"
                        variant="outline"
                        className="h-14 text-base"
                    >
                        <MonitorX />
                        {t('games.remote.guest_board.close')}
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('games.remote.guest_board.close_confirm.title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t(
                            'games.remote.guest_board.close_confirm.description',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...closeBoard.form(boardId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    onHttpException={(response) => {
                        setOpen(false);

                        return forgetGoneBoard(response);
                    }}
                >
                    {({ processing }) => (
                        <DialogFooter>
                            <DialogClose asChild>
                                <Button type="button" variant="outline">
                                    {t('common.cancel')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('games.remote.guest_board.close')}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The guest board was closed on the wall («Lövhəni bağla», «Mənim deyil»)
 * or expired since this page loaded: its routes answer 404. Instead of the
 * error, the page asks the server again which board shows the game.
 */
function forgetGoneBoard(response: { status: number }): boolean | void {
    if (response.status !== 404) {
        return;
    }

    router.reload({ only: ['guestBoard'] });

    return false;
}

const NO_VIEW: LiveRemoteView = {
    socket: 'connecting',
    board: 'waiting',
    state: null,
    status: null,
    taps: [],
    ackMs: [],
};

const noSubscription = () => () => {};

function useRemoteView(link: LiveRemote | null): LiveRemoteView {
    return useSyncExternalStore(
        link?.subscribe ?? noSubscription,
        link?.getView ?? (() => NO_VIEW),
        () => NO_VIEW,
    );
}

/**
 * The game's rounds, from `GET /games/{game}/state` (the teacher may read
 * it like the board). Asked again when the board names a round the phone
 * does not know, and every 3 s after a failure.
 */
function useRounds(
    gameId: string,
    roundId: string | null,
    wanted: boolean,
): Round[] | null {
    const [rounds, setRounds] = useState<Round[] | null>(null);
    const [attempt, setAttempt] = useState(0);
    const unknown =
        roundId !== null &&
        rounds !== null &&
        !rounds.some((round) => round.id === roundId);

    useEffect(() => {
        if (!wanted) {
            return;
        }

        let cancelled = false;
        let handle: number | undefined;

        boardHttp(gameId)
            .state()
            .then((state) => {
                if (!cancelled) {
                    setRounds(state.rounds);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    handle = window.setTimeout(
                        () => setAttempt((n) => n + 1),
                        3000,
                    );
                }
            });

        return () => {
            cancelled = true;
            window.clearTimeout(handle);
        };
    }, [gameId, wanted, attempt, unknown]);

    return rounds;
}

/** This phone tab, for `client-hello`; kept across a reload of the tab. */
function deviceId(): string {
    const key = 'elifba.remote.device';

    try {
        const stored = window.sessionStorage.getItem(key);

        if (stored !== null) {
            return stored;
        }

        const made = ulid(Date.now());

        window.sessionStorage.setItem(key, made);

        return made;
    } catch {
        return ulid(Date.now());
    }
}
