import { CircleStop, Flag, Handshake, Play, Trophy, Zap } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import InputError from '@/components/input-error';
import { CheckersBoard } from '@/components/rooms/checkers/checkers-board';
import {
    clockLabel,
    completeMove,
    nextSquares,
    piecesOf,
    timeLeft,
} from '@/components/rooms/checkers/match';
import type {
    RoomMatch,
    RoomMatchState,
    Side,
} from '@/components/rooms/checkers/match';
import { useMatch, useTicking } from '@/components/rooms/checkers/use-match';
import { useMatchSound } from '@/components/rooms/checkers/use-match-sound';
import { RoomActionBar } from '@/components/rooms/room-action-bar';
import { InviteCard } from '@/components/rooms/room-code';
import type { JoinLink } from '@/components/rooms/room-code';
import { RoomConfirm } from '@/components/rooms/room-confirm';
import {
    FrameTitle,
    LockedBadge,
    RoomFrame,
} from '@/components/rooms/room-frame';
import { RoomTools } from '@/components/rooms/room-tools';
import { SoundToggle } from '@/components/rooms/sound-toggle';
import { JoinedChips, StandingCard } from '@/components/rooms/start-parts';
import { TauntReaction, useTauntsHidden } from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { VoiceSwitchRow } from '@/components/rooms/voice-bar';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import matchRoutes from '@/routes/rooms/match';
import moves from '@/routes/rooms/match/moves';
import { eventId } from '@/rooms/answer';
import type { RoomPlayer, RoomState } from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';
import type { RoomSoundUrls } from '@/rooms/sound';

/**
 * A room opened for checkers (docs/frontend.md rule 15 "Board games", ADR
 * 034): outside a match the invitation, who has joined and — for the host —
 * who plays white and black and «Başla»; during a match the board, the two
 * players with their pieces left, and whose turn it is. The player to move
 * taps a piece and then each square it lands on, among the moves the
 * server listed; the whole path is posted and the page reads the match
 * again. Everyone else in the room watches the same board.
 *
 * The match is read again whenever the room's revision rises — a move
 * raises it like any change — so it follows `useLiveRoom()`'s state.
 */
export function CheckersRoom({
    roomId,
    state,
    initial,
    host,
    canLeave,
    join,
    refresh,
    voice,
    placeLabels,
    sounds,
    taunts,
}: {
    roomId: string;
    state: RoomState;
    initial: RoomMatchState | null;
    host: boolean;
    canLeave: boolean;
    join: JoinLink | null;
    refresh: () => void;
    voice?: ReactNode;
    placeLabels: string[];
    sounds: RoomSoundUrls | null;
    taunts: TauntList | null;
}) {
    const { t } = useTranslation();
    const { view, reload, serverNow } = useMatch({
        roomId,
        initial,
        revision: state.revision,
    });
    // In a room without a big screen the phone plays the room's moments too (ADR 028 as amended).
    const sound = useMatchSound({
        speaker: 'phone',
        sounds,
        view,
        carries: !state.room.screen,
    });
    const [tauntsHidden, setTauntsHidden] = useTauntsHidden('phone');
    const match = view?.match ?? null;
    const running = match !== null && !match.over;
    const players = useMemo(
        () => new Map(state.players.map((player) => [player.id, player])),
        [state.players],
    );
    const after = () => {
        reload();
        refresh();
    };

    const tools = host && !running && (
        <RoomTools
            roomId={roomId}
            state={state}
            onChanged={refresh}
            voice={
                state.voice !== null ? (
                    <VoiceSwitchRow
                        roomId={roomId}
                        state={state}
                        onChanged={refresh}
                    />
                ) : null
            }
        />
    );

    return (
        <RoomFrame
            heading={
                <FrameTitle
                    title={t('rooms.boards.checkers.name')}
                    badge={state.room.locked ? <LockedBadge /> : null}
                />
            }
            tools={
                <>
                    {sounds !== null && <SoundToggle sound={sound} />}
                    {voice}
                    {tools}
                </>
            }
        >
            {running && view !== null ? (
                <LiveMatch
                    roomId={roomId}
                    match={match}
                    view={view}
                    players={players}
                    playerList={state.players}
                    host={host}
                    onChanged={after}
                    serverNow={serverNow}
                    sound={sound}
                    taunts={taunts}
                    tauntsOn={state.room.taunts}
                    tauntsHidden={tauntsHidden}
                    onTauntsHiddenChange={setTauntsHidden}
                />
            ) : (
                <Lobby
                    roomId={roomId}
                    state={state}
                    last={match}
                    players={players}
                    host={host}
                    canLeave={canLeave}
                    join={join}
                    onChanged={after}
                    placeLabels={placeLabels}
                />
            )}
        </RoomFrame>
    );
}

function nameOf(
    players: Map<string, RoomPlayer>,
    id: string,
    nameless: string,
): string {
    return players.get(id)?.name ?? nameless;
}

/** The board while the match runs, with the two players and the moves of the one to move. */
function LiveMatch({
    roomId,
    match,
    view,
    players,
    playerList,
    host,
    onChanged,
    serverNow,
    sound,
    taunts,
    tauntsOn,
    tauntsHidden,
    onTauntsHiddenChange,
}: {
    roomId: string;
    match: RoomMatch;
    view: RoomMatchState;
    players: Map<string, RoomPlayer>;
    playerList: RoomPlayer[];
    host: boolean;
    onChanged: () => void;
    /** The server's clock as the page estimates it. */
    serverNow: () => number;
    sound: ReturnType<typeof useMatchSound>;
    taunts: TauntList | null;
    tauntsOn: boolean;
    tauntsHidden: boolean;
    onTauntsHiddenChange: (hidden: boolean) => void;
}) {
    const { t } = useTranslation();
    const mySide = view.me.side;
    const myTurn = mySide !== null && mySide === match.turn;
    const [selected, setSelected] = useState<number[]>([]);
    const [sending, setSending] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const now = useTicking(serverNow, true);

    // A new position clears what was tapped.
    useEffect(() => {
        setSelected([]);
    }, [match.ply]);

    const legal = myTurn && !sending ? match.moves : [];
    const startsNow = new Set(legal.map((move) => move[0]));
    const targets =
        selected.length === 0
            ? new Set<number>()
            : nextSquares(legal, selected);

    const send = (path: number[]) => {
        setSending(true);
        setError(null);
        roomRequest('POST', moves.store.url(roomId), {
            event_id: eventId(serverNow()),
            ply: match.ply,
            path,
        })
            .then((reply) => {
                if (reply.status !== 200) {
                    setError(t('rooms.match.move_failed'));
                } else {
                    sound.sent();
                }
            })
            .catch(() => setError(t('rooms.match.move_failed')))
            .finally(() => {
                setSending(false);
                setSelected([]);
                onChanged();
            });
    };

    // A piece, then each square it lands on; another of one's pieces starts over before the first landing.
    const tap = (square: number) => {
        if (targets.has(square)) {
            const next = [...selected, square];
            const move = completeMove(legal, next);
            setSelected(next);

            if (move === null) {
                sound.tap('tap');
            }

            if (move !== null) {
                send(move);
            }

            return;
        }

        if (selected.length <= 1 && startsNow.has(square)) {
            sound.tap(selected[0] === square ? 'undo' : 'tap');
            setSelected(selected[0] === square ? [] : [square]);
        }
    };

    const flipped = mySide === 'b';
    const top: Side = flipped ? 'w' : 'b';
    const bottom: Side = flipped ? 'b' : 'w';
    const nameless = t('rooms.show.nameless');
    const opponent: Side | null =
        mySide === null ? null : mySide === 'w' ? 'b' : 'w';
    const lineOf = (side: Side) => ({
        side,
        name: nameOf(
            players,
            side === 'w' ? match.white : match.black,
            nameless,
        ),
        pieces: piecesOf(match.board, side),
        toMove: match.turn === side,
        mine: mySide === side,
        clockMs: timeLeft(match, side, view.server_ms, now),
        throwAt:
            side === opponent && tauntsOn && view.taunts.open ? (
                <MatchTauntThrow
                    roomId={roomId}
                    list={taunts}
                    readyMs={view.taunts.ready_ms}
                    now={now}
                    serverNow={serverNow}
                    hidden={tauntsHidden}
                    onHiddenChange={onTauntsHiddenChange}
                    onThrown={onChanged}
                />
            ) : null,
    });

    return (
        <>
            <TauntReaction
                received={view.taunts.received}
                players={playerList}
                list={taunts}
                now={now}
                hidden={tauntsHidden}
            />
            <div className="mx-auto flex w-full max-w-xl flex-col gap-3 px-4 pt-3 pb-4 md:px-6">
                <PlayerLine {...lineOf(top)} />
                <CheckersBoard
                    board={match.board}
                    flipped={flipped}
                    lastPath={match.last_path}
                    selected={selected}
                    targets={targets}
                    starts={selected.length <= 1 ? startsNow : new Set()}
                    onTap={myTurn && !sending ? tap : null}
                />
                <PlayerLine {...lineOf(bottom)} />
                <p
                    aria-live="polite"
                    className="text-center text-sm text-muted-foreground"
                >
                    {sending ? (
                        <Spinner className="inline" />
                    ) : myTurn ? (
                        t(
                            match.capture
                                ? 'rooms.match.your_turn_capture'
                                : 'rooms.match.your_turn',
                        )
                    ) : mySide !== null ? (
                        t('rooms.match.their_turn')
                    ) : (
                        t('rooms.match.watching')
                    )}
                </p>
                {error !== null && <InputError message={error} />}
            </div>

            {(mySide !== null || host) && (
                <RoomActionBar framed>
                    <div className="flex justify-center gap-2">
                        {mySide !== null && (
                            <RoomConfirm
                                trigger={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11 text-muted-foreground"
                                    >
                                        <Flag />
                                        {t('rooms.match.resign')}
                                    </Button>
                                }
                                title={t('rooms.match.resign_title')}
                                description={t(
                                    'rooms.match.resign_description',
                                )}
                                confirmLabel={t('rooms.match.resign')}
                                destructive
                                onConfirm={() =>
                                    roomRequest(
                                        'POST',
                                        matchRoutes.resign.url(roomId),
                                    ).finally(onChanged)
                                }
                            />
                        )}
                        {host && (
                            <RoomConfirm
                                trigger={
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        className="h-11 text-muted-foreground"
                                    >
                                        <CircleStop />
                                        {t('rooms.game.end')}
                                    </Button>
                                }
                                title={t('rooms.game.end_title')}
                                description={t('rooms.match.end_description')}
                                confirmLabel={t('rooms.game.end')}
                                destructive
                                onConfirm={() =>
                                    roomRequest(
                                        'DELETE',
                                        matchRoutes.destroy.url(roomId),
                                    ).finally(onChanged)
                                }
                            />
                        )}
                    </div>
                </RoomActionBar>
            )}
        </>
    );
}

/** One player's line above or below the board: the side's piece, the name, the pieces left, the clock and whose turn it is. */
function PlayerLine({
    side,
    name,
    pieces,
    toMove,
    mine,
    clockMs,
    throwAt,
}: {
    side: Side;
    name: string;
    pieces: number;
    toMove: boolean;
    mine: boolean;
    clockMs: number;
    /** The opponent's line on a player's phone: the taunt button. */
    throwAt: ReactNode;
}) {
    const { t } = useTranslation();
    const low = toMove && clockMs <= 30_000;

    return (
        <div
            className={cn(
                'relative flex h-12 items-center gap-3 rounded-2xl border px-3 transition-colors motion-reduce:transition-none',
                toMove ? 'border-primary bg-accent' : 'bg-card',
            )}
        >
            <span
                aria-hidden
                className={cn(
                    'size-6 shrink-0 rounded-full border-[3px]',
                    side === 'w'
                        ? 'border-stone-300 bg-stone-50'
                        : 'border-neutral-700 bg-neutral-900',
                )}
            />
            <span className="min-w-0 flex-1 truncate font-semibold">
                {name}
                {mine && (
                    <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                        {t('rooms.show.you')}
                    </span>
                )}
            </span>
            <span className="shrink-0 text-sm text-muted-foreground tabular-nums">
                {t('rooms.match.pieces', { count: pieces })}
            </span>
            <span
                role="timer"
                aria-label={t('rooms.match.time_left', {
                    time: clockLabel(clockMs),
                })}
                className={cn(
                    'shrink-0 rounded-md px-1.5 py-0.5 font-mono text-[15px] font-semibold tabular-nums',
                    toMove ? 'bg-primary text-primary-foreground' : 'bg-muted',
                    low && 'bg-red-600 text-white',
                )}
            >
                {clockLabel(clockMs)}
            </span>
            {throwAt}
        </div>
    );
}

/**
 * The taunt button on the opponent's line (ADR 034 decision 11): it opens
 * the taunts as a grid under the line; a tap throws one at the opponent,
 * free, and the button is the cooldown's count until it may again. The
 * same list, emoji and phrases as a round game's taunts; «Mənə atılanları
 * gizlət» hides those aimed at this phone.
 */
function MatchTauntThrow({
    roomId,
    list,
    readyMs,
    now,
    serverNow,
    hidden,
    onHiddenChange,
    onThrown,
}: {
    roomId: string;
    list: TauntList | null;
    readyMs: number | null;
    now: number;
    serverNow: () => number;
    hidden: boolean;
    onHiddenChange: (hidden: boolean) => void;
    onThrown: () => void;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [sending, setSending] = useState(false);
    const [note, setNote] = useState<string | null>(null);
    const box = useRef<HTMLDivElement>(null);
    const cooling = readyMs !== null && readyMs > now;

    // A tap outside, or Escape, closes it.
    useEffect(() => {
        if (!open) {
            return;
        }

        const outside = (event: PointerEvent) => {
            if (!box.current?.contains(event.target as Node | null)) {
                setOpen(false);
            }
        };
        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') {
                setOpen(false);
            }
        };

        document.addEventListener('pointerdown', outside);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('pointerdown', outside);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    if (list === null || list.length === 0) {
        return null;
    }

    const send = (key: string) => {
        setSending(true);
        setNote(null);
        roomRequest('POST', matchRoutes.taunts.store.url(roomId), {
            event_id: eventId(serverNow()),
            taunt: key,
        })
            .then((reply) => {
                const status = (reply.body as { status?: string } | null)
                    ?.status;

                if (status === 'accepted' || status === 'duplicate') {
                    setOpen(false);
                } else if (status === 'cooling' || status === 'off') {
                    setNote(
                        t(`rooms.taunts.${status === 'off' ? 'off' : 'late'}`),
                    );
                } else {
                    setNote(t('rooms.taunts.late'));
                }
            })
            .catch(() => setNote(t('rooms.taunts.late')))
            .finally(() => {
                setSending(false);
                onThrown();
            });
    };

    return (
        <div ref={box} className="shrink-0">
            <Button
                type="button"
                variant="outline"
                size="icon"
                className="size-9 rounded-full tabular-nums"
                aria-label={t('rooms.taunts.tray')}
                aria-expanded={open}
                onClick={() => setOpen((was) => !was)}
            >
                {cooling ? (
                    <span className="text-xs font-semibold">
                        {Math.ceil((readyMs - now) / 1000)}
                    </span>
                ) : (
                    <Zap className="size-4" />
                )}
            </Button>
            {open && (
                <div className="absolute top-full right-0 left-0 z-40 mt-2 flex flex-col gap-2 rounded-2xl border bg-popover p-3 text-popover-foreground shadow-lg">
                    <p className="text-sm text-muted-foreground">
                        {cooling
                            ? t('rooms.taunts.cooling', {
                                  seconds: Math.ceil((readyMs - now) / 1000),
                              })
                            : t('rooms.match.taunt_free')}
                    </p>
                    <ul className="grid grid-cols-4 gap-1.5">
                        {list.map((taunt) => (
                            <li key={taunt.key}>
                                <button
                                    type="button"
                                    disabled={sending || cooling}
                                    onClick={() => send(taunt.key)}
                                    className="flex w-full flex-col items-center gap-0.5 rounded-xl p-1.5 text-center outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring disabled:opacity-40"
                                >
                                    <img
                                        src={taunt.url}
                                        alt=""
                                        className="size-9"
                                    />
                                    <span className="line-clamp-2 text-[11px] leading-tight">
                                        {t(`rooms.taunts.say.${taunt.key}`)}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    {note !== null && <InputError message={note} />}
                    <label className="flex items-center gap-2 text-sm">
                        <input
                            type="checkbox"
                            checked={hidden}
                            onChange={(event) =>
                                onHiddenChange(event.target.checked)
                            }
                            className="size-4"
                        />
                        {t('rooms.taunts.hide')}
                    </label>
                </div>
            )}
        </div>
    );
}

/** Outside a match: the last match's result, the invitation, who joined and the host's start. */
function Lobby({
    roomId,
    state,
    last,
    players,
    host,
    canLeave,
    join,
    onChanged,
    placeLabels,
}: {
    roomId: string;
    state: RoomState;
    last: RoomMatch | null;
    players: Map<string, RoomPlayer>;
    host: boolean;
    canLeave: boolean;
    join: JoinLink | null;
    onChanged: () => void;
    placeLabels: string[];
}) {
    const { t } = useTranslation();
    const present = state.players.filter((player) => player.present);
    const [white, setWhite] = useState<string | null>(null);
    const [black, setBlack] = useState<string | null>(null);
    const [starting, setStarting] = useState(false);
    const [failed, setFailed] = useState(false);
    const presentIds = present.map((player) => player.id);
    // The host's picks while they are present; else the first two present players.
    const shownWhite =
        white !== null && presentIds.includes(white)
            ? white
            : (presentIds[0] ?? null);
    const shownBlack =
        black !== null && presentIds.includes(black) && black !== shownWhite
            ? black
            : (presentIds.find((id) => id !== shownWhite) ?? null);
    const ready = shownWhite !== null && shownBlack !== null;

    const start = () => {
        if (!ready) {
            return;
        }

        setStarting(true);
        setFailed(false);
        roomRequest('POST', matchRoutes.store.url(roomId), {
            white: shownWhite,
            black: shownBlack,
        })
            .then((reply) => setFailed(reply.status !== 204))
            .catch(() => setFailed(true))
            .finally(() => {
                setStarting(false);
                onChanged();
            });
    };

    const swap = () => {
        setWhite(shownBlack);
        setBlack(shownWhite);
    };

    return (
        <>
            <div className="mx-auto flex w-full max-w-xl flex-col gap-3 px-4 pt-3 pb-4 md:px-6">
                {last !== null && last.over && (
                    <MatchResult match={last} players={players} />
                )}
                {state.ranking.length > 0 && (
                    <StandingCard state={state} placeLabels={placeLabels} />
                )}
                {state.room.code !== null && (host || last === null) && (
                    <InviteCard
                        code={state.room.code}
                        join={join}
                        qr={last !== null ? 120 : 160}
                    />
                )}
                <section className="flex flex-col gap-2">
                    <h2 className="text-sm font-semibold">
                        {t('rooms.start.joined_count', {
                            count: present.length,
                        })}
                    </h2>
                    <JoinedChips
                        roomId={roomId}
                        state={state}
                        removable={host}
                    />
                </section>
                {host ? (
                    <section className="flex flex-col gap-2 rounded-2xl border bg-card p-3">
                        <h2 className="text-sm font-semibold">
                            {t('rooms.match.sides')}
                        </h2>
                        <SidePicker
                            side="w"
                            value={shownWhite}
                            players={present}
                            onChange={setWhite}
                        />
                        <SidePicker
                            side="b"
                            value={shownBlack}
                            players={present.filter(
                                (player) => player.id !== shownWhite,
                            )}
                            onChange={setBlack}
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            className="h-10 self-start text-sm"
                            disabled={!ready}
                            onClick={swap}
                        >
                            {t('rooms.match.swap')}
                        </Button>
                    </section>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {t('rooms.match.host_starts')}
                    </p>
                )}
            </div>

            <RoomActionBar framed>
                {failed && <InputError message={t('rooms.setup.failed')} />}
                {host ? (
                    <Button
                        type="button"
                        className="h-13 w-full text-base"
                        disabled={!ready || starting}
                        onClick={start}
                    >
                        {starting ? <Spinner /> : <Play />}
                        {ready
                            ? t('rooms.match.start')
                            : t('rooms.match.need_two')}
                    </Button>
                ) : (
                    canLeave && (
                        <p className="text-center text-sm text-muted-foreground">
                            {t('rooms.match.waiting')}
                        </p>
                    )
                )}
            </RoomActionBar>
        </>
    );
}

function SidePicker({
    side,
    value,
    players,
    onChange,
}: {
    side: Side;
    value: string | null;
    players: RoomPlayer[];
    onChange: (id: string) => void;
}) {
    const { t } = useTranslation();
    const id = `side-${side}`;

    return (
        <div className="flex items-center gap-3">
            <span
                aria-hidden
                className={cn(
                    'size-6 shrink-0 rounded-full border-[3px]',
                    side === 'w'
                        ? 'border-stone-300 bg-stone-50'
                        : 'border-neutral-700 bg-neutral-900',
                )}
            />
            <label htmlFor={id} className="w-20 shrink-0 text-sm">
                {t(`rooms.match.side.${side}`)}
            </label>
            <select
                id={id}
                value={value ?? ''}
                onChange={(event) => onChange(event.target.value)}
                className="h-11 min-w-0 flex-1 rounded-md border bg-background px-3 text-base"
            >
                {value === null && <option value="">—</option>}
                {players.map((player) => (
                    <option key={player.id} value={player.id}>
                        {player.name ?? t('rooms.show.nameless')}
                    </option>
                ))}
            </select>
        </div>
    );
}

/** How the last match ended: the winner, or a draw, or the host's end. */
function MatchResult({
    match,
    players,
}: {
    match: RoomMatch;
    players: Map<string, RoomPlayer>;
}) {
    const { t } = useTranslation();
    const nameless = t('rooms.show.nameless');
    const winner =
        match.result === 'w'
            ? nameOf(players, match.white, nameless)
            : match.result === 'b'
              ? nameOf(players, match.black, nameless)
              : null;

    return (
        <section
            aria-label={t('rooms.match.result')}
            className="flex items-center gap-3 rounded-2xl border border-primary bg-card p-4"
        >
            {match.result === 'draw' ? (
                <Handshake className="size-8 shrink-0 text-primary" />
            ) : (
                <Trophy className="size-8 shrink-0 text-amber-500" />
            )}
            <span className="flex min-w-0 flex-col">
                <span className="truncate text-lg font-semibold">
                    {winner !== null
                        ? t('rooms.match.won', { name: winner })
                        : match.result === 'draw'
                          ? t('rooms.match.draw')
                          : t('rooms.match.ended')}
                </span>
                {match.ending !== null && (
                    <span className="text-sm text-muted-foreground">
                        {t(`rooms.match.ending.${match.ending}`)}
                    </span>
                )}
            </span>
        </section>
    );
}
