import {
    Check,
    Hourglass,
    Lock,
    Minus,
    TimerOff,
    Undo2,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { MECHANIC_VIEWS } from '@/components/rooms/mechanics/views';
import type { AskTools } from '@/components/rooms/mechanics/views';
import { rowOf } from '@/components/rooms/mechanics/word-layout';
import { REVEAL_MS } from '@/components/rooms/motion';
import { rivalOf } from '@/components/rooms/rivalry';
import { FrameTitle, ScreenHead } from '@/components/rooms/game-head';
import { GameStrip, useRivalry } from '@/components/rooms/rivalry-strip';
import type { RivalryIcons } from '@/components/rooms/rivalry-strip';
import {
    SpentLine,
    TauntReaction,
    TauntThrow,
} from '@/components/rooms/taunt-parts';
import type { TauntList } from '@/components/rooms/taunt-parts';
import { seconds } from '@/components/rooms/seconds';
import { Standing } from '@/components/rooms/standing';
import { tap, untap } from '@/components/rooms/taps';
import { TimeBar, URGENT_MS } from '@/components/rooms/time-bar';
import { useCountUp, usePreviousReveal } from '@/components/rooms/use-motion';
import type { RoomMotion } from '@/components/rooms/use-motion';
import type { RoomSound } from '@/components/rooms/use-room-sound';
import { secondsUntil, useServerNow } from '@/components/rooms/use-server-now';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { store as answersRoute } from '@/routes/rooms/answers';
import { store as lettersRoute } from '@/routes/rooms/letters';
import { eventId, sendAnswer } from '@/rooms/answer';
import { barStretch } from '@/rooms/clock';
import type { ServerClock } from '@/rooms/clock';
import type {
    RoomAnswer,
    RoomBoardGame,
    RoomGame,
    RoomMine,
    RoomRaceGame,
    RoomRound,
    RoomState,
} from '@/rooms/contract';
import { roomRequest } from '@/rooms/http';
import { sendLetter } from '@/rooms/letter';

const NOTES = {
    sending: 'rooms.game.sending',
    accepted: 'rooms.game.sent',
    duplicate: 'rooms.game.sent',
    answered: 'rooms.game.already',
    late: 'rooms.game.late',
    refused: 'rooms.game.refused',
    failed: 'rooms.game.failed',
} as const;

type Sent = {
    answer: RoomAnswer;
    status: keyof typeof NOTES;
};

/**
 * The current round of a live game as the state shows it (docs/frontend.md
 * rule 15, "A round"; architecture §12 "The state", "An answer"): the
 * countdown, the material to study — only while the state carries it and
 * the server's clock is before the question — the question, then the
 * reveal with the viewer's verdict, the solution and the game's ranking
 * with each player's result of the round. The page draws the phase the
 * state says; the seconds and the time bar are the server's clock as
 * estimated, for show only.
 *
 * An answer is the items tapped (`tap()`), sent by the tap that makes
 * `ask.pick` items, with one event id that every retry repeats. Its
 * receipt says only whether it was taken; right or wrong comes from the
 * reveal.
 *
 * Each moment moves as ADR 025 designs it (rule 15 "Motion"): its block is
 * keyed on the round and the phase and carries `data-motion="on"` when the
 * page saw it begin (`motion`), so a state fetched again replays nothing
 * and the moment the page opened on stands still. The answer sent moves
 * the same for every answer; right and wrong show only from the reveal.
 * A tap sounds the same for every item, and a tap taken back («Geri», a
 * cell tapped again) has its own sound (`sound`, the page's: rule 15
 * "Sound"); the answer sent and the verdict sound from the state.
 *
 * Its title is the game frame's top bar (`RoundTitle`, ADR 032), and the
 * round opens with the rivalry strip (rule 15 "Rivals face to face", ADR
 * 027) over the time row: the viewer against the nearest rival, or the
 * screen's pair for a host who does not play. It moves only in a reveal
 * the page saw begin.
 *
 * The strip's rival side is the throw (rule 15 "Taunts", ADR 029 as
 * amended 2026-11-19): a player taps it and throws the strip's rival a
 * taunt from the fan (`taunts`), for a point of their own; a taunt aimed at
 * the viewer is a reaction in the middle of the viewport for 3 s, which
 * takes no tap and moves nothing of the round; only the host turns taunts
 * off (ADR 029 decision 55). The reveal says the cost counted at
 * its close: «Atmaca: −:count xal» under the thrower's verdict and
 * «−:count» in «Sıralama».
 *
 * A round that sells letters (ADR 037: «Anaqram», its question has orders)
 * also carries the viewer's bought letters (`round.letters`): the answer
 * being made is the bought tiles first, then the viewer's own taps, which
 * a purchase takes back. «Hərf al» posts the next place with one event id
 * per tap and waits for the state; buying the last letter fills the row,
 * which is sent as a tap that fills it is. The reveal says how many
 * letters the viewer bought beside the points.
 */
export function LiveRound({
    roomId,
    state,
    game,
    round,
    clock,
    placeLabels,
    refresh,
    onGone,
    motion,
    sound = null,
    icons = null,
    taunts = null,
}: {
    roomId: string;
    state: RoomState;
    game: RoomRaceGame;
    round: RoomRound;
    clock: ServerClock;
    placeLabels: string[];
    refresh: () => void;
    onGone: () => void;
    /** Whether the page saw this round and this phase begin (`useRoomMotion()`). */
    motion: RoomMotion;
    /** The page's sound, for the viewer's own taps. */
    sound?: RoomSound | null;
    /** The rivalry strip's emoji (the prop `rivalry`). */
    icons?: RivalryIcons | null;
    /** The taunts a player may throw (the prop `taunts`, T2). */
    taunts?: TauntList | null;
}) {
    const { t } = useTranslation();
    const now = useServerNow(clock, true);
    const view = MECHANIC_VIEWS[game.mechanic];
    const previous = usePreviousReveal(game, round, state.ranking);
    const rivalry = useRivalry(state, state.me.player_id);
    const spent = round.reveal?.spent ?? [];
    // ADR 037: the viewer's bought letters, locked in the first places while the round takes answers.
    const letters = round.phase === 'answer' ? round.letters : null;
    const bought = letters?.bought ?? [];
    // The viewer's own taps, after the bought letters: a purchase takes them back.
    const [draft, setDraft] = useState<{
        round: string;
        bought: number;
        ids: string[];
    }>({ round: '', bought: 0, ids: [] });
    const [sent, setSent] = useState<Sent | null>(null);
    // A purchase on its way: its round and the place it buys, until the state shows it bought.
    const [buying, setBuying] = useState<{
        round: string;
        position: number;
    } | null>(null);
    // The round whose every letter was bought and whose row this page has sent.
    const filledSent = useRef<string | null>(null);
    // What `keepTrying` reads while an answer is retried.
    const shownRound = useRef(round);

    useEffect(() => {
        shownRound.current = round;
    }, [round]);

    const own =
        draft.round === round.id && draft.bought === bought.length
            ? draft.ids
            : [];
    const picked = rowOf(bought, own);
    const mySent = sent?.answer.round_id === round.id ? sent : null;
    const plays = state.me.player_id !== null;
    const locked =
        !plays ||
        round.answered ||
        (mySent !== null &&
            mySent.status !== 'refused' &&
            mySent.status !== 'failed');

    const send = async (answer: RoomAnswer) => {
        setSent({ answer, status: 'sending' });

        const outcome = await sendAnswer({
            answer,
            post: (body) => roomRequest('POST', answersRoute.url(roomId), body),
            wait: (ms) =>
                new Promise((resolve) => window.setTimeout(resolve, ms)),
            keepTrying: () =>
                shownRound.current.id === answer.round_id &&
                shownRound.current.phase === 'answer',
        });

        switch (outcome.kind) {
            case 'receipt':
                setSent({ answer, status: outcome.receipt.status });
                refresh();
                break;
            case 'refused':
                setSent({ answer, status: 'refused' });
                setDraft({
                    round: answer.round_id,
                    bought: bought.length,
                    ids: [],
                });
                refresh();
                break;
            case 'gone':
                onGone();
                break;
            case 'failed':
                setSent({ answer, status: 'failed' });
                refresh();
                break;
        }
    };

    // What the page says of this round's answer: its own receipt, else the state.
    const note = mySent?.status ?? (round.answered ? 'accepted' : null);

    const pick = (id: string) => {
        if (locked || round.ask === null) {
            return;
        }

        const next = tap(round.ask, picked, id);
        // The same sound for every item; a cell tapped again is taken back.
        if (next.ids.length !== picked.length) {
            sound?.tap(next.ids.length > picked.length ? 'tap' : 'undo');
        }

        setDraft({
            round: round.id,
            bought: bought.length,
            ids: next.ids.slice(bought.length),
        });

        if (next.send) {
            void send({
                event_id: eventId(clock.now() ?? 0),
                round_id: round.id,
                picked: next.ids,
            });
        }
    };

    const buyingNow =
        buying !== null &&
        buying.round === round.id &&
        buying.position >= bought.length;

    // «Hərf al» (ADR 037 decision 12): the next place, one event id per tap; the tiles come with the state.
    const buy = async () => {
        if (
            locked ||
            buyingNow ||
            letters === null ||
            letters.price === null ||
            round.ask === null
        ) {
            return;
        }

        const position = letters.bought.length;
        const roundId = round.id;

        setBuying({ round: roundId, position });

        const outcome = await sendLetter({
            letter: {
                event_id: eventId(clock.now() ?? 0),
                round_id: roundId,
                position,
            },
            post: (body) => roomRequest('POST', lettersRoute.url(roomId), body),
            wait: (ms) =>
                new Promise((resolve) => window.setTimeout(resolve, ms)),
            keepTrying: () =>
                shownRound.current.id === roundId &&
                shownRound.current.phase === 'answer',
        });

        if (outcome.kind === 'gone') {
            onGone();

            return;
        }

        refresh();

        const taken =
            outcome.kind === 'receipt' &&
            (outcome.receipt.status === 'accepted' ||
                outcome.receipt.status === 'duplicate');

        // A bought letter waits for its state; anything else frees «Hərf al» at once — and a state that never comes, after a while.
        window.setTimeout(
            () =>
                setBuying((current) =>
                    current?.round === roundId && current.position === position
                        ? null
                        : current,
                ),
            taken ? 4000 : 0,
        );
    };

    // Every letter bought fills the row: it is sent as a tap that fills it is (ADR 037 decision 15).
    useEffect(() => {
        if (
            round.phase !== 'answer' ||
            round.ask === null ||
            letters === null ||
            letters.bought.length !== round.ask.pick ||
            locked ||
            filledSent.current === round.id
        ) {
            return;
        }

        filledSent.current = round.id;
        void send({
            event_id: eventId(clock.now() ?? 0),
            round_id: round.id,
            picked: letters.bought,
        });
    });

    const tools: AskTools | null =
        plays && !locked
            ? {
                  undo:
                      own.length === 0
                          ? null
                          : () => {
                                sound?.tap('undo');
                                setDraft({
                                    round: round.id,
                                    bought: bought.length,
                                    ids: untap(own),
                                });
                            },
                  buy: () => void buy(),
                  buying: buyingNow,
                  tapped: () => sound?.tap('tap'),
              }
            : null;

    const shown =
        round.answered && mySent !== null ? mySent.answer.picked : picked;
    // Once the answer is sent, its note says how many have answered: the taps' row has nothing left to do.
    const sentNote = note !== null && note !== 'failed';
    const answeredCount =
        round.phase === 'answer'
            ? t('rooms.game.answered_count', {
                  count: round.answered_count,
                  total: round.present_count,
              })
            : '';

    return (
        <section
            aria-labelledby="round-heading"
            data-phase={round.phase}
            data-round={round.index}
            className="flex flex-col gap-4"
        >
            <GameStrip
                rivalry={rivalry}
                state={state}
                placeLabels={placeLabels}
                icons={icons}
                moves={motion.strip}
            >
                <TauntThrow
                    roomId={roomId}
                    state={state}
                    rival={rivalOf(rivalry.pair, state.players)}
                    list={taunts}
                    clock={clock}
                    now={now}
                    phase={`${round.index}:${round.phase}`}
                    onThrown={refresh}
                />
            </GameStrip>

            <TauntReaction
                received={state.taunts?.received}
                players={state.players}
                list={taunts}
                now={now}
            />

            <RoundHead game={game} round={round} now={now} />

            <div
                key={`${round.index}:${round.phase}`}
                data-motion={motion.phase ? 'on' : undefined}
                className="flex flex-col gap-4"
            >
                {round.phase === 'countdown' && (
                    <Countdown game={game} until={round.opens_ms} now={now} />
                )}

                {round.phase === 'study' && (
                    <div className="flex flex-col items-center gap-3">
                        <p className="text-center font-semibold room-motion:animate-room-enter">
                            {t(`rooms.mechanics.${game.mechanic}.study`)}
                        </p>
                        {round.study !== null ? (
                            <view.Study
                                study={round.study}
                                covered={
                                    now === null || now >= round.answer_from_ms
                                }
                            />
                        ) : (
                            <Hourglass
                                aria-hidden
                                className="my-8 size-8 text-muted-foreground"
                            />
                        )}
                    </div>
                )}

                {round.phase === 'answer' && round.ask !== null && (
                    <div className="flex flex-col gap-3">
                        <p className="text-center font-semibold room-motion:animate-room-enter">
                            {t(`rooms.mechanics.${game.mechanic}.ask`)}
                        </p>
                        <view.Ask
                            ask={round.ask}
                            picked={shown}
                            disabled={locked}
                            onPick={pick}
                            letters={letters}
                            tools={tools}
                        />

                        {!sentNote && (
                            <div
                                className={cn(
                                    'flex items-center gap-3',
                                    // A question with its own tools leaves this row the count alone.
                                    view.drawsTools ? 'min-h-5' : 'min-h-11',
                                )}
                            >
                                {plays &&
                                    !locked &&
                                    !view.drawsTools &&
                                    (round.ask.shape === 'sequence' ? (
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className="h-11"
                                            disabled={own.length === 0}
                                            onClick={() => {
                                                sound?.tap('undo');
                                                setDraft({
                                                    round: round.id,
                                                    bought: bought.length,
                                                    ids: untap(own),
                                                });
                                            }}
                                        >
                                            <Undo2 />
                                            {t('rooms.game.undo')}
                                        </Button>
                                    ) : round.ask.shape === 'set' ? (
                                        <span className="text-sm text-muted-foreground tabular-nums">
                                            {t('rooms.game.picked', {
                                                count: picked.length,
                                                total: round.ask.pick,
                                            })}
                                        </span>
                                    ) : null)}
                                <span className="ml-auto flex shrink-0 items-center gap-1.5 text-sm text-muted-foreground tabular-nums">
                                    <Users aria-hidden className="size-4" />
                                    <Counted value={round.answered_count}>
                                        {answeredCount}
                                    </Counted>
                                </span>
                            </div>
                        )}

                        <div role="status" aria-live="polite">
                            {!plays && (
                                <p className="text-center text-sm text-muted-foreground">
                                    {t('rooms.game.watching')}
                                </p>
                            )}
                            {plays &&
                                note === null &&
                                round.ask.shape === 'set' &&
                                picked.length > 0 && (
                                    <p className="text-center text-xs text-muted-foreground">
                                        {t('rooms.game.untap_hint')}
                                    </p>
                                )}
                            {sentNote && (
                                <AnswerNote
                                    note={note}
                                    count={answeredCount}
                                    answered={round.answered_count}
                                    own={mySent !== null}
                                />
                            )}
                            {mySent?.status === 'failed' && (
                                <div className="flex flex-col items-center gap-2 rounded-xl border border-destructive/30 bg-destructive/10 p-3 text-sm">
                                    <span className="text-red-600 dark:text-red-400">
                                        {t('rooms.game.failed')}
                                    </span>
                                    <Button
                                        type="button"
                                        className="h-11"
                                        onClick={() => void send(mySent.answer)}
                                    >
                                        {t('rooms.game.resend')}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {round.phase === 'reveal' &&
                    round.ask !== null &&
                    round.reveal !== null && (
                        <div className="flex flex-col gap-4">
                            <Verdict
                                mine={round.reveal.mine}
                                plays={plays}
                                motion={motion.phase}
                                spent={
                                    spent.find(
                                        (cost) =>
                                            cost.player_id ===
                                            state.me.player_id,
                                    )?.count ?? 0
                                }
                            />
                            <div
                                className="room-motion:animate-room-enter"
                                style={{
                                    animationDelay: `${REVEAL_MS.solution}ms`,
                                }}
                            >
                                <view.Solution
                                    ask={round.ask}
                                    solution={round.reveal.solution}
                                    mine={round.reveal.mine}
                                    word={round.reveal.word}
                                />
                            </div>
                            <section
                                className="flex flex-col gap-2 room-motion:animate-room-enter"
                                style={{
                                    animationDelay: `${REVEAL_MS.ranking}ms`,
                                }}
                            >
                                <h3 className="text-sm font-semibold">
                                    {t('rooms.game.ranking')}
                                </h3>
                                <Standing
                                    ranking={state.ranking}
                                    players={state.players}
                                    me={state.me.player_id}
                                    placeLabels={placeLabels}
                                    results={round.reveal.results}
                                    spent={spent}
                                    motion={
                                        motion.phase
                                            ? {
                                                  previous,
                                                  delay: REVEAL_MS.ranking,
                                              }
                                            : null
                                    }
                                />
                            </section>
                        </div>
                    )}
            </div>
        </section>
    );
}

/**
 * The countdown to a game's start: «Oyun başlayır» over the seconds in a
 * ring — each second's figure drops in and the ring ripples, in a moment
 * the page saw begin — then the game's name and what it asks. `until` is
 * the moment it counts to: a race's round 0 `opens_ms`, a match's
 * `starts_ms` (ADR 034 "Amended 2026-11-21 — the countdown over the
 * board"). The seconds are the estimated server clock's, for show only.
 */
export function Countdown({
    game,
    until,
    now,
    big = false,
}: {
    game: Pick<RoomGame, 'mechanic'> | Pick<RoomBoardGame, 'mechanic'>;
    until: number;
    now: number | null;
    big?: boolean;
}) {
    const { t } = useTranslation();
    const left = secondsUntil(until, now);

    return (
        <div
            className={cn(
                'flex flex-col items-center text-center',
                big ? 'flex-1 justify-center gap-[2.4vh]' : 'gap-3 py-4',
            )}
        >
            <span
                className={cn(
                    'text-muted-foreground room-motion:animate-room-enter',
                    big ? 'text-[3.4vh]' : 'text-sm',
                )}
            >
                {t('rooms.game.countdown')}
            </span>
            <span
                className={cn(
                    'relative flex items-center justify-center rounded-full border-primary/15',
                    big ? 'size-[36vh] border-[1vh]' : 'size-36 border-4',
                )}
            >
                <span
                    key={`ring-${left}`}
                    aria-hidden
                    className={cn(
                        'absolute rounded-full border-primary/40 opacity-0 room-motion:animate-room-ripple',
                        big ? '-inset-[1vh] border-[1vh]' : '-inset-1 border-4',
                    )}
                />
                <span
                    key={left}
                    className={cn(
                        'leading-none font-bold tabular-nums room-motion:animate-room-tick',
                        big ? 'text-[20vh]' : 'text-7xl',
                    )}
                >
                    {left}
                </span>
            </span>
            <span
                className={cn(
                    'font-semibold room-motion:animate-room-enter',
                    big ? 'text-[4.4vh]' : 'text-lg',
                )}
                style={{ animationDelay: '120ms' }}
            >
                {t(`rooms.mechanics.${game.mechanic}.name`)}
            </span>
            <p
                className={cn(
                    'text-balance text-muted-foreground room-motion:animate-room-enter',
                    big ? 'max-w-[60vw] text-[2.8vh]' : 'max-w-xs text-sm',
                )}
                style={{ animationDelay: '180ms' }}
            >
                {t(`rooms.mechanics.${game.mechanic}.line`)}
            </p>
        </div>
    );
}

/**
 * A number of the state that pops when it changes — the count of answers —
 * but not on the value it had when it was first drawn.
 */
export function Counted({
    value,
    children,
}: {
    value: number;
    children: ReactNode;
}) {
    const [first] = useState(value);

    return (
        <span
            key={value}
            className={cn(
                'inline-block',
                value !== first && 'motion-safe:animate-room-pop',
            )}
        >
            {children}
        </span>
    );
}

/** The moment the shown phase ends, for its seconds: the first round's opening, the question, the bell, the next round. */
function phaseEnd(round: RoomRound): number | null {
    switch (round.phase) {
        case 'countdown':
            return round.opens_ms;
        case 'study':
            return round.answer_from_ms;
        case 'answer':
            return round.bell_ms;
        case 'reveal':
            return round.next_ms;
    }
}

/** «Raund :index / :total» — in the reveal «Raund :index / :total bitdi», so the head says in words that the round is over. */
function useRoundTitle(round: RoomRound): string {
    const { t } = useTranslation();

    return t(
        round.phase === 'reveal' ? 'rooms.game.round_over' : 'rooms.game.round',
        { index: round.index + 1, total: round.total },
    );
}

/**
 * A round's title in the game frame's top bar (ADR 032 decision 4; rule 15
 * "The game frame"): the page's `h1`, «Raund :index / :total» — «… bitdi»
 * in the reveal — over the game and its difficulty, one line each,
 * truncated. It pops when the page saw the round begin (`motion`, ADR 025).
 */
export function RoundTitle({
    game,
    round,
    motion = false,
}: {
    game: RoomGame;
    round: RoomRound;
    motion?: boolean;
}) {
    const { t } = useTranslation();

    return (
        <FrameTitle
            title={useRoundTitle(round)}
            titleKey={round.index}
            line={`${t(`rooms.mechanics.${game.mechanic}.name`)} · ${t(`rooms.difficulty.${game.difficulty}`)}`}
            motion={motion}
        />
    );
}

/**
 * A round's head. On a phone its time row only — the time bar with the
 * seconds left, or «Növbəti: :seconds san.» in the reveal: the round's
 * title is the game frame's top bar (`RoundTitle`, ADR 032). On the screen
 * (`big`) the game and its difficulty over «Raund :index / :total» — «…
 * bitdi» in the reveal — and the seconds, then the time bar.
 */
export function RoundHead({
    game,
    round,
    now,
    big = false,
    motion = false,
}: {
    game: RoomGame;
    round: RoomRound;
    now: number | null;
    big?: boolean;
    /** The page saw this round begin: its number pops in (on the screen). */
    motion?: boolean;
}) {
    const { t } = useTranslation();
    const title = useRoundTitle(round);
    const end = phaseEnd(round);
    const seconds = end === null ? null : secondsUntil(end, now);
    const last = round.index + 1 === round.total;
    const left =
        seconds === null || round.phase === 'countdown'
            ? null
            : round.phase === 'reveal'
              ? t(last ? 'rooms.game.final' : 'rooms.game.next', { seconds })
              : t('rooms.game.seconds', { seconds });
    // The last seconds of an answer: red, each second with a pulse, as the bar turns.
    const hurry =
        round.phase === 'answer' &&
        now !== null &&
        seconds !== null &&
        seconds > 0 &&
        round.bell_ms - now <= URGENT_MS;
    const time =
        left === null ? null : (
            <span
                key={hurry ? `hurry-${seconds}` : 'calm'}
                className={cn(
                    'shrink-0 font-medium tabular-nums',
                    big ? 'text-[3.4vh]' : 'text-sm',
                    hurry &&
                        'text-red-600 motion-safe:animate-room-pop dark:text-red-400',
                )}
            >
                {left}
            </span>
        );
    const bar = (
        <TimeBar
            stretch={barStretch(round)}
            now={now}
            urgent={round.phase === 'answer'}
            className={big ? 'h-[1.2vh]' : 'min-w-0 flex-1'}
        />
    );

    if (big) {
        return (
            <ScreenHead
                line={`${t(`rooms.mechanics.${game.mechanic}.name`)} · ${t(`rooms.difficulty.${game.difficulty}`)}`}
                title={title}
                titleKey={round.index}
                motion={motion}
                aside={time}
            >
                {bar}
            </ScreenHead>
        );
    }

    return (
        <div className="flex min-h-5 items-center gap-3">
            {bar}
            {time}
        </div>
    );
}

/**
 * What the page says of the viewer's answer while the round is open: never
 * whether it is right. Sent on this page (`own`), it rises in; while the
 * room answers its hourglass turns — one movement for every answer.
 */
function AnswerNote({
    note,
    count,
    answered,
    own,
}: {
    note: Exclude<keyof typeof NOTES, 'failed'>;
    /** «Cavab verib: :count / :total». */
    count: string;
    /** The count of answers, which pops when it changes. */
    answered: number;
    /** The answer was sent from this page, not read from a reloaded state. */
    own: boolean;
}) {
    const { t } = useTranslation();
    const icon =
        note === 'sending' ? (
            <Spinner />
        ) : note === 'late' ? (
            <TimerOff aria-hidden className="size-5 shrink-0" />
        ) : note === 'refused' ? (
            <X aria-hidden className="size-5 shrink-0" />
        ) : (
            <Hourglass
                aria-hidden
                className="size-5 shrink-0 motion-safe:animate-room-wait"
            />
        );

    return (
        <p
            className={cn(
                'flex items-center justify-center gap-2.5 rounded-xl border bg-muted/50 px-3 py-2.5 text-center text-sm',
                own && 'motion-safe:animate-room-enter',
                note === 'refused' &&
                    'border-destructive/30 bg-destructive/10 text-red-600 dark:text-red-400',
            )}
        >
            {icon}
            <span className="flex flex-col">
                <span className="font-medium">{t(NOTES[note])}</span>
                <span className="text-muted-foreground tabular-nums">
                    <Counted value={answered}>{count}</Counted>
                </span>
            </span>
        </p>
    );
}

/**
 * The viewer's own result of the round, from the reveal only: right with
 * its points and time, wrong, or no answer. A host who does not play has
 * none. In a reveal the page saw begin it comes in first, the same way
 * whatever the server judged, and its points count up from 0. Under it,
 * the viewer's taunts counted at this close (T2): «Atmaca: −:count xal».
 * Under the points, the letters the viewer bought in the round (ADR 037
 * decision 19, `mine.letters`), so a halved or empty score explains itself.
 */
function Verdict({
    mine,
    plays,
    motion,
    spent = 0,
}: {
    mine: RoomMine | null;
    plays: boolean;
    motion: boolean;
    /** The viewer's taunts counted at this close. */
    spent?: number;
}) {
    const { t } = useTranslation();
    const points = useCountUp(mine?.points ?? 0, {
        enabled: motion,
        delay: 200,
        duration: 600,
    });

    if (!plays) {
        return null;
    }

    if (mine === null) {
        return (
            <div className="flex items-center gap-3 rounded-2xl border bg-muted/50 p-3 room-motion:animate-room-enter">
                <span
                    className="flex size-12 shrink-0 items-center justify-center rounded-full bg-muted room-motion:animate-room-pop"
                    style={{ animationDelay: '120ms' }}
                >
                    <Minus aria-hidden className="size-6" />
                </span>
                <span className="flex min-w-0 flex-col">
                    <span className="font-semibold">
                        {t('rooms.game.no_answer')}
                    </span>
                    {spent > 0 && <SpentLine count={spent} />}
                </span>
            </div>
        );
    }

    const time = seconds(mine.elapsed_ms, t('rooms.decimal_mark'), true);

    return (
        <div
            className={cn(
                'flex items-center gap-3 rounded-2xl border p-3 room-motion:animate-room-enter',
                mine.correct
                    ? 'border-emerald-600/30 bg-emerald-600/10'
                    : 'border-destructive/30 bg-destructive/10',
            )}
        >
            <span
                className={cn(
                    'flex size-12 shrink-0 items-center justify-center rounded-full text-white room-motion:animate-room-pop',
                    mine.correct ? 'bg-emerald-600' : 'bg-destructive',
                )}
                style={{ animationDelay: '120ms' }}
            >
                {mine.correct ? (
                    <Check aria-hidden className="size-7" strokeWidth={3} />
                ) : (
                    <X aria-hidden className="size-7" strokeWidth={3} />
                )}
            </span>
            <span className="flex min-w-0 flex-1 flex-col">
                <span
                    className={cn(
                        'text-xl leading-tight font-bold',
                        mine.correct
                            ? 'text-emerald-700 dark:text-emerald-300'
                            : 'text-red-600 dark:text-red-400',
                    )}
                >
                    {mine.correct
                        ? t('rooms.game.correct')
                        : t('rooms.game.wrong')}
                </span>
                <span className="text-sm text-muted-foreground tabular-nums">
                    {t('rooms.game.elapsed', { seconds: time })}
                </span>
                {spent > 0 && (
                    <span className="text-sm">
                        <SpentLine count={spent} />
                    </span>
                )}
            </span>
            <span className="flex shrink-0 flex-col items-end gap-1">
                <span className="text-xl font-bold tabular-nums">
                    {t('rooms.game.points_won', { count: points })}
                </span>
                {mine.letters > 0 && (
                    <span
                        data-letters={mine.letters}
                        className="inline-flex items-center gap-1 rounded-full bg-primary px-2 py-0.5 text-xs font-semibold whitespace-nowrap text-primary-foreground tabular-nums"
                    >
                        <Lock
                            aria-hidden
                            className="size-3"
                            strokeWidth={2.5}
                        />
                        {t('rooms.anagram.letters', { count: mine.letters })}
                    </span>
                )}
            </span>
        </div>
    );
}
