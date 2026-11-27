/**
 * The server's clock and when a room page asks for the state again
 * (docs/architecture.md §12 "The race" → "The state", "The channel",
 * ADR 021). The server alone keeps the clock: a round's phase is what the
 * state says, and the page never decides one or sends its own time. It
 * only needs to know when to ask, so it estimates the server's clock from
 * the `server_ms` of each state it fetches and asks again at the moment
 * the current phase ends — `opens_ms`, `answer_from_ms`, the bell,
 * `next_ms` — sent half a round trip ahead, so that it reaches the server
 * just after the moment. A page that asked a little early is told the same
 * phase with the server's own `server_ms`, which says how much of it was
 * left, and asks again once that has passed; told it again, it waits a
 * little longer each time, so a clock that is off never makes a hot loop
 * (`room-state` allows 120 requests a minute). In the lobby and while it
 * waits for the others in an open round, where only a signal would tell
 * it of a change, a page also asks every 1.5 s while it does not hear the
 * channel and every 3 s while it does, since a socket can be open and
 * deaf.
 *
 * The local clock and the timers come from outside, so Vitest fakes them;
 * nothing here judges, scores or derives a phase.
 */
import type { RoomRound, RoomState } from './contract';
import { POLL_MS } from './live';

/** A local clock in milliseconds; monotonic in the browser. */
export type LocalClock = () => number;

export type Delays = {
    after: (callback: () => void, ms: number) => unknown;
    cancel: (handle: unknown) => void;
};

/**
 * The server's clock as the page estimates it. Each state's `server_ms`
 * was read between the request's send and receive times, so it bounds the
 * difference between the two clocks; the bounds of every state are
 * intersected, and the estimate is the middle — for one state, the
 * midpoint of its own send and receive times. A state whose bounds miss
 * the others' (the device slept, its clock jumped) starts the estimate
 * again. A state in the page's props has no send time, only the moment it
 * was there by: until a fetched one comes, the server is taken to have
 * read its clock at that moment.
 */
export type ServerClock = {
    observe: (
        serverMs: number,
        sentAt: number | null,
        receivedAt: number,
    ) => void;
    /** The server's clock now, or null before the first state. */
    now: () => number | null;
    /**
     * How long a request takes to reach the server by the estimate: half
     * the narrowest window the states left between the two clocks — 0
     * until a fetched state bounds it from both sides.
     */
    trip: () => number;
};

const localNow: LocalClock = () => performance.now();

export function serverClock(local: LocalClock = localNow): ServerClock {
    // The server's clock minus the local one lies in [low, high].
    let low = -Infinity;
    let high = Infinity;

    return {
        observe: (serverMs, sentAt, receivedAt) => {
            const from = serverMs - receivedAt;
            const to = sentAt === null ? Infinity : serverMs - sentAt;

            if (from > high || to < low) {
                low = from;
                high = to;
            } else {
                low = Math.max(low, from);
                high = Math.min(high, to);
            }
        },
        now: () => {
            if (low === -Infinity) {
                return null;
            }

            return local() + (high === Infinity ? low : (low + high) / 2);
        },
        trip: () =>
            low === -Infinity || high === Infinity ? 0 : (high - low) / 2,
    };
}

/**
 * The moment the shown phase ends by the timetable: the countdown at
 * `opens_ms`, the study at `answer_from_ms`, the answer at the bell (or
 * earlier, which only a state tells), the reveal at `next_ms`. Null in the
 * lobby and once the game is over, where nothing waits for a moment.
 */
export function phaseEnds(state: RoomState): number | null {
    const round = state.round;

    // A live domino game has three moments of time (ADR 038 decision 40): the first hand's opening, the flag, the pause's end.
    if (state.domino !== null) {
        switch (state.domino.phase) {
            case 'countdown':
                return state.domino.starts_ms;
            case 'play':
                return state.domino.flag_ms;
            case 'sheet':
                return state.domino.sheet.until_ms;
            default:
                return null;
        }
    }

    if (round === null) {
        // A live match ends its phase at two moments only: the clock's start and the flag (ADR 034 decision 18).
        switch (state.match?.phase) {
            case 'countdown':
                return state.match.starts_ms;
            case 'play':
                return state.match.flag_ms;
            default:
                return null;
        }
    }

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

/**
 * Whether only a signal or a state tells the page of its next change: in
 * the lobby and after a game, where nothing comes but a start, and in a
 * round's question once it has answered — or when it answers nothing: a
 * host who does not play, and the big screen (`answers` false), whose
 * viewer answers on a phone, so its own `answered` stays false while the
 * room answers — where it waits for the others' answers or the early
 * close (`waitsForOthers()`). There the page asks every `POLL_MS` while it
 * does not hear the channel, and every `WAIT_POLL_MS` while it does. The
 * countdown, the study and the reveal end at their moments, which the
 * page asks about anyway.
 */
export function waitsForSignal(state: RoomState, answers = true): boolean {
    return state.round === null || waitsForOthers(state, answers);
}

/**
 * Whether the page waits in a round's question for the others: it has
 * answered, or answers nothing (a host who does not play, the screen).
 * Only a signal or a state tells it of the early close, so it asks every
 * `WAIT_POLL_MS` even while it hears the channel, and every `POLL_MS`
 * while it does not.
 */
export function waitsForOthers(state: RoomState, answers = true): boolean {
    const round = state.round;

    // A live match waits for the other seat's act, which only a signal or a state tells (ADR 034 decision 18).
    if (state.match !== null && state.match.phase !== 'over') {
        return true;
    }

    // So does a live domino game, for every seat's play and the server's for a seat (ADR 038 decision 40).
    if (state.domino !== null && state.domino.phase !== 'over') {
        return true;
    }

    return (
        round !== null &&
        round.phase === 'answer' &&
        (!answers || round.answered || state.me.player_id === null)
    );
}

/**
 * How often a page that hears the channel still asks where only a signal
 * tells it of a change (`waitsForSignal()`). A socket can stay open and
 * hear nothing — a phone that changed networks — until its ping times
 * out: the rooms-flow check (2026-11-18) saw such a phone left on the
 * question it had answered while the room closed it and played two more
 * rounds without it, since a round waits only for the phones that read
 * the state, and one in the lobby miss a game's first three rounds. Asked
 * within the 3 s reveal, the page reads in time to be waited for in the
 * next round; in the lobby it learns of a start within the 3 s countdown.
 */
export const WAIT_POLL_MS = 3000;

/** A moment is asked about this long after it, so the server is past it too. */
export const LATE_MS = 60;

/**
 * At most this much of a request's way to the server is sent ahead of a
 * moment: a first slow request makes the estimate's window wide.
 */
export const LEAD_MAX_MS = 250;

/**
 * How long a page waits before asking again when it was told the same
 * phase twice after its end, or when its request failed — a little longer
 * each time in a row.
 */
export const AGAIN_MS = [250, 500, 1000, 2000, 3000] as const;

/** The wait before the n-th time in a row (from 1). */
function again(times: number): number {
    return AGAIN_MS[Math.min(Math.max(times - 1, 0), AGAIN_MS.length - 1)];
}

const realDelays: Delays = {
    after: (callback, ms) => window.setTimeout(callback, ms),
    cancel: (handle) => window.clearTimeout(handle as number),
};

export type RoomFollow = {
    /** The page now shows this state: plan the next request from it. */
    show: (state: RoomState) => void;
    /** Whether the room's channel is heard (`watchRoom`'s `onListening`). */
    listening: (heard: boolean) => void;
    /** A request — whoever made it — brought no state: no answer, a server error, the limiter. */
    failed: () => void;
    stop: () => void;
};

/**
 * Plans one request at a time from the state the page shows: at the end
 * of its phase, sent half a round trip ahead (`ServerClock.trip()`, at
 * most `LEAD_MAX_MS`) so that it reaches the server `LATE_MS` after the
 * moment; sooner where `waitsForSignal()` says so: every `POLL_MS` while
 * the channel is not heard, every `WAIT_POLL_MS` while it is. Told the
 * same phase after its end, the page asks again once the time the server
 * said was left (the phase's end minus the state's `server_ms`) has
 * passed, then a little later each time. A request that failed — one this
 * plan made, or one a signal, a subscription or an answer started — is
 * made again shortly, a little later each time, until a state is shown: a
 * page in the lobby that hears the channel would otherwise wait for the
 * next signal or its next poll, and miss the start of a game.
 */
export function followRoom(options: {
    clock: ServerClock;
    refresh: () => void;
    delays?: Delays;
    /** Whether this page answers for its viewer: false for the big screen. */
    answers?: boolean;
}): RoomFollow {
    const answers = options.answers ?? true;
    const delays = options.delays ?? realDelays;
    let shown: RoomState | null = null;
    let heard = false;
    let pending: unknown = null;
    let stopped = false;
    // The phase last shown, and how many times in a row it was shown after its end.
    let phase = '';
    let late = 0;
    // Told a phase after its end: when, on the estimate, the server's own word says it is over.
    let over: number | null = null;
    // Requests that failed since a state was last shown.
    let misses = 0;

    const cancel = () => {
        if (pending !== null) {
            delays.cancel(pending);
            pending = null;
        }
    };

    const ask = () => {
        pending = null;
        options.refresh();
    };

    const plan = () => {
        cancel();

        if (stopped || shown === null) {
            return;
        }

        const ends = phaseEnds(shown);
        const now = options.clock.now();
        let wait: number | null = null;

        if (ends !== null) {
            const lead = Math.min(options.clock.trip(), LEAD_MAX_MS);

            if (now !== null && ends - now > 0) {
                wait = Math.max(1, ends - now - lead + LATE_MS);
            } else if (late === 1 && over !== null && now !== null) {
                wait = Math.max(1, over - now);
            } else {
                wait = again(late);
            }
        }

        if (waitsForSignal(shown, answers)) {
            const every = heard ? WAIT_POLL_MS : POLL_MS;
            wait = Math.min(wait ?? every, every);
        }

        if (misses > 0) {
            wait = Math.min(wait ?? Infinity, again(misses));
        }

        if (wait !== null) {
            pending = delays.after(ask, wait);
        }
    };

    return {
        show: (state) => {
            const round = state.round;
            // A match's phase is its ply's: each move begins another (ADR 034 decision 29).
            const key = `${state.game?.id ?? ''}.${round?.id ?? state.match?.ply ?? ''}.${round?.phase ?? state.match?.phase ?? ''}`;
            const ends = phaseEnds(state);
            const now = options.clock.now();
            const ended = ends !== null && now !== null && ends - now <= 0;

            late = ended ? (key === phase ? late + 1 : 1) : 0;
            // The server read its clock this long before the end: ask once that has passed.
            over =
                ended && ends !== null && now !== null
                    ? now + Math.max(0, ends - state.server_ms) + LATE_MS
                    : null;
            phase = key;
            shown = state;
            misses = 0;
            plan();
        },
        listening: (next) => {
            heard = next;
            plan();
        },
        failed: () => {
            misses++;
            plan();
        },
        stop: () => {
            stopped = true;
            cancel();
        },
    };
}

/**
 * How much of a stretch of the timetable is left at `now` on the server's
 * clock, from 1 at `from` to 0 at `to` (docs/frontend.md rule 15, "The
 * time bar"). `now` is the estimated server clock (`ServerClock.now()`),
 * never the device's, so two phones whose clocks disagree draw the same
 * bar. For show only: it decides nothing. Before the first state (`now`
 * null) the bar is full.
 */
export function timeLeft(from: number, to: number, now: number | null): number {
    if (now === null) {
        return 1;
    }

    if (to <= from) {
        return 0;
    }

    return Math.min(1, Math.max(0, (to - now) / (to - from)));
}

/**
 * The stretch a round's time bar measures in its phase: the study from
 * the round's opening to the question, the answer from the question to
 * the bell, the reveal from the close to the next round's opening (after
 * the last, to the game's end). The countdown has no bar: the state does
 * not say when it began.
 */
export function barStretch(
    round: RoomRound,
): { from: number; to: number } | null {
    switch (round.phase) {
        case 'countdown':
            return null;
        case 'study':
            return { from: round.opens_ms, to: round.answer_from_ms };
        case 'answer':
            return { from: round.answer_from_ms, to: round.bell_ms };
        case 'reveal':
            return { from: round.closes_ms, to: round.next_ms };
    }
}
