import { describe, expect, it, vi } from 'vite-plus/test';
import type { Delays, ServerClock } from './clock';
import {
    AGAIN_MS,
    LATE_MS,
    LEAD_MAX_MS,
    WAIT_POLL_MS,
    barStretch,
    followRoom,
    phaseEnds,
    serverClock,
    timeLeft,
    waitsForOthers,
    waitsForSignal,
} from './clock';
import { RoomState } from './contract';
import { POLL_MS } from './live';

/*
 * When a room page asks for the state (docs/architecture.md §12 "The
 * state", "The channel"): the server's clock is estimated from a state's
 * `server_ms` against the request's send and receive times; the page asks
 * again when the shown phase ends, sent half a round trip ahead, again
 * once the server's own word says the phase is over when it was early,
 * and only in the lobby and while it waits for the others every 1.5 s
 * without a channel, every 3 s with one.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): RoomState {
    return RoomState.parse(
        fixtures[`../../../tests/Fixtures/rooms/state.${name}.json`],
    );
}

/** A local clock and timers that move only when the test moves them. */
function fakeTime(start = 0) {
    let now = start;
    let next = 1;
    const pending = new Map<number, { at: number; callback: () => void }>();
    const delays: Delays = {
        after: (callback, ms) => {
            expect(ms).toBeGreaterThan(0);
            pending.set(next, { at: now + ms, callback });

            return next++;
        },
        cancel: (handle) => {
            pending.delete(handle as number);
        },
    };

    return {
        delays,
        pending,
        local: () => now,
        /** Moves the clock, running each timer whose moment comes, in order. */
        advance: (ms: number) => {
            const end = now + ms;

            for (;;) {
                const due = [...pending.entries()]
                    .filter(([, timer]) => timer.at <= end)
                    .sort(([, a], [, b]) => a.at - b.at)[0];

                if (due === undefined) {
                    break;
                }

                pending.delete(due[0]);
                now = due[1].at;
                due[1].callback();
            }

            now = end;
        },
        /** When the next timer fires, from now. */
        nextIn: () =>
            Math.min(...[...pending.values()].map((timer) => timer.at)) - now,
    };
}

/** The server's clock is 100 s ahead of the local one; both start together. */
const OFFSET = 100_000;

function synced(time: ReturnType<typeof fakeTime>): ServerClock {
    const clock = serverClock(time.local);
    clock.observe(time.local() + OFFSET, time.local(), time.local());

    return clock;
}

/** One round's moments, on the server's clock. */
const T = {
    opens: 103_000,
    answerFrom: 106_000,
    bell: 116_000,
    next: 119_000,
};

/** A fixture's state in its phase, moved onto the moments above. */
function at(name: string, patch: Record<string, unknown> = {}): RoomState {
    const fixture = state(name);
    const round =
        fixture.round === null
            ? null
            : {
                  ...fixture.round,
                  opens_ms: T.opens,
                  answer_from_ms: T.answerFrom,
                  bell_ms: T.bell,
                  ...(fixture.round.phase === 'reveal'
                      ? { closes_ms: T.bell, next_ms: T.next }
                      : {}),
                  ...patch,
              };

    return RoomState.parse({ ...fixture, round });
}

function follow(time: ReturnType<typeof fakeTime>, clock = synced(time)) {
    const refresh = vi.fn();
    const follow = followRoom({ clock, refresh, delays: time.delays });

    return { follow, refresh, clock };
}

describe('serverClock', () => {
    it("estimates the server's clock from a state", () => {
        const time = fakeTime(1000);
        const clock = serverClock(time.local);
        expect(clock.now()).toBeNull();

        // Sent at 1000, received at 1200: the server read 50 000 at 1100.
        time.advance(200);
        clock.observe(50_000, 1000, 1200);
        expect(clock.now()).toBe(50_100);
        time.advance(300);
        expect(clock.now()).toBe(50_400);

        // A quicker request narrows it: read between 2000 and 2020.
        time.advance(520);
        clock.observe(51_005, 2000, 2020);
        // The difference lies in [48 800, 49 000] ∩ [48 985, 49 005].
        expect(clock.now()).toBe(2020 + 48_992.5);
    });

    it('takes a state of the props as read when it was there, until a fetched one comes', () => {
        const time = fakeTime(5000);
        const clock = serverClock(time.local);

        clock.observe(80_000, null, 5000);
        expect(clock.now()).toBe(80_000);

        // The props were older than they looked: a fetched state moves the estimate on.
        time.advance(300);
        clock.observe(80_400, 5100, 5300);
        expect(clock.now()).toBe(5300 + 75_200);
    });

    it('starts again when a state misses the bounds of the others', () => {
        const time = fakeTime(0);
        const clock = serverClock(time.local);
        clock.observe(10_000, 0, 100);

        // The device slept and its clock stood still: the server is 60 s further on.
        time.advance(200);
        clock.observe(70_300, 100, 200);
        expect(clock.now()).toBe(200 + 70_150);
    });
});

describe('phaseEnds', () => {
    it('ends each phase at its moment of the timetable', () => {
        expect(phaseEnds(at('countdown'))).toBe(T.opens);
        expect(phaseEnds(at('study'))).toBe(T.answerFrom);
        expect(phaseEnds(at('answer'))).toBe(T.bell);
        expect(phaseEnds(at('reveal'))).toBe(T.next);
        expect(phaseEnds(state('lobby'))).toBeNull();
        expect(phaseEnds(state('over'))).toBeNull();
    });
});

describe('followRoom', () => {
    it('asks again at each moment of the timetable', () => {
        const time = fakeTime();
        const { follow: room, refresh } = follow(time);
        room.listening(true);

        // The countdown ends when round 0 opens, a little after the moment.
        room.show(at('countdown'));
        expect(time.nextIn()).toBe(3000 + LATE_MS);
        time.advance(3000 + LATE_MS - 1);
        expect(refresh).not.toHaveBeenCalled();
        time.advance(1);
        expect(refresh).toHaveBeenCalledTimes(1);

        // The study ends at the question, the question at the bell, the reveal at the next round.
        room.show(at('study'));
        expect(time.nextIn()).toBe(
            T.answerFrom - (T.opens + LATE_MS) + LATE_MS,
        );
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(2);

        room.show(at('answer', { answered: false, answered_count: 0 }));
        expect(time.nextIn()).toBe(T.bell - T.answerFrom);
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(3);

        room.show(at('reveal'));
        expect(time.nextIn()).toBe(T.next - T.bell);
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(4);

        // Over: no moment to wait for, only the lobby's poll for the next start.
        room.show(state('over'));
        expect(phaseEnds(state('over'))).toBeNull();
        expect(time.nextIn()).toBe(WAIT_POLL_MS);
    });

    it('asks again when the server says how much of the phase was left, then a little later each time', () => {
        const time = fakeTime();
        // The estimate is a second ahead of the server, which keeps telling the study.
        const clock = serverClock(time.local);
        clock.observe(OFFSET + 1000, 0, 0);
        const { follow: room, refresh } = follow(time, clock);
        room.listening(true);

        room.show(at('study'));
        expect(time.nextIn()).toBe(T.answerFrom - (OFFSET + 1000) + LATE_MS);
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(1);

        // Early by the server's clock: it read 106 000 − 40 when it said «study».
        // The page asks again once those 40 ms have passed, not after a blind wait.
        room.show(
            RoomState.parse({ ...at('study'), server_ms: T.answerFrom - 40 }),
        );
        expect(time.nextIn()).toBe(40 + LATE_MS);
        time.advance(time.nextIn());
        expect(refresh).toHaveBeenCalledTimes(2);

        // Told the same phase again (a clock that is off): a little later each time, never a hot loop.
        const waits: number[] = [];

        for (let i = 0; i < 6; i++) {
            room.show(
                RoomState.parse({
                    ...at('study'),
                    server_ms: T.answerFrom - 40,
                }),
            );
            waits.push(time.nextIn());
            time.advance(time.nextIn());
        }

        expect(waits).toEqual([...AGAIN_MS.slice(1), AGAIN_MS[4], AGAIN_MS[4]]);
        expect(AGAIN_MS).toEqual([250, 500, 1000, 2000, 3000]);
        expect(refresh).toHaveBeenCalledTimes(8);

        // Another phase after its end starts the count again, from the server's word.
        room.show(
            RoomState.parse({
                ...at('answer', { bell_ms: T.answerFrom + 5000 }),
                server_ms: T.answerFrom + 4900,
            }),
        );
        expect(time.nextIn()).toBe(100 + LATE_MS);
    });

    it("sends a moment's request half a round trip ahead, so it reaches the server just after the moment", () => {
        const time = fakeTime();
        const clock = serverClock(time.local);
        // Sent at 0, back at 300: the server read its clock somewhere in between.
        time.advance(300);
        clock.observe(OFFSET + 150, 0, 300);
        expect(clock.trip()).toBe(150);
        const { follow: room } = follow(time, clock);
        room.listening(true);

        // The study ends at 106 000 on the server's clock, 5 700 ms from now by the estimate:
        // a request on its way 150 ms is sent 150 ms ahead, and reaches the server 60 ms after.
        room.show(at('study'));
        expect(clock.now()).toBe(OFFSET + 300);
        expect(time.nextIn()).toBe(
            T.answerFrom - (OFFSET + 300) - 150 + LATE_MS,
        );

        // A first slow request makes the window wide: never more than LEAD_MAX_MS ahead.
        const slow = serverClock(time.local);
        slow.observe(OFFSET + 1300, 300, 2300);
        expect(slow.trip()).toBe(1000);
        const late = follow(time, slow);
        late.follow.listening(true);
        late.follow.show(at('study'));
        expect(time.nextIn()).toBe(
            T.answerFrom - (slow.now() ?? 0) - LEAD_MAX_MS + LATE_MS,
        );

        // Before a fetched state bounds it from both sides, nothing is sent ahead.
        const props = serverClock(time.local);
        props.observe(OFFSET, null, time.local());
        expect(props.trip()).toBe(0);
    });

    it('polls when there is no socket', () => {
        const time = fakeTime();
        const { follow: room, refresh } = follow(time);

        // The lobby: every 1.5 s while the channel is not heard, every 3 s while it is.
        room.show(state('lobby'));
        expect(time.nextIn()).toBe(POLL_MS);
        time.advance(POLL_MS);
        room.show(state('lobby'));
        time.advance(POLL_MS);
        expect(refresh).toHaveBeenCalledTimes(2);

        room.listening(true);
        expect(time.nextIn()).toBe(WAIT_POLL_MS);
        room.listening(false);
        expect(time.nextIn()).toBe(POLL_MS);

        // A question not yet answered: only at the bell.
        const unanswered = at('answer', { answered: false, answered_count: 0 });
        expect(waitsForSignal(unanswered)).toBe(false);
        room.show(unanswered);
        expect(time.nextIn()).toBe(T.bell - (OFFSET + 2 * POLL_MS) + LATE_MS);

        // Once answered, every 1.5 s: an early close is told only by a state.
        room.show(at('answer'));
        expect(time.nextIn()).toBe(POLL_MS);
        expect(POLL_MS).toBe(1500);

        // The countdown, the study and the reveal: only at their moments,
        // whoever answered — the reveal ends when its `next_ms` comes.
        expect(waitsForSignal(at('countdown'))).toBe(false);
        expect(waitsForSignal(at('study'))).toBe(false);
        expect(waitsForSignal(at('reveal', { answered: false }))).toBe(false);
        expect(waitsForSignal(at('reveal', { answered: true }))).toBe(false);
        room.show(at('reveal', { answered: true }));
        expect(time.nextIn()).toBe(T.next - (OFFSET + 2 * POLL_MS) + LATE_MS);

        // A host who does not play waits as one who answered; after a game, as in the lobby.
        expect(
            waitsForSignal(
                RoomState.parse({
                    ...unanswered,
                    me: { player_id: null, host: true },
                }),
            ),
        ).toBe(true);
        expect(waitsForSignal(state('over'))).toBe(true);
    });

    it('polls through every question on the screen, whose viewer answers on a phone', () => {
        // Without a socket the screen showed 6 of 10 questions and 1 of 10 reveals
        // (rooms-flow check, 2026-11-18): its own `answered` stays false while the
        // host answers on the phone, so it waited for the bell after every early close.
        const time = fakeTime();
        const refresh = vi.fn();
        const screen = followRoom({
            clock: synced(time),
            refresh,
            delays: time.delays,
            answers: false,
        });
        const unanswered = at('answer', { answered: false, answered_count: 0 });

        expect(waitsForSignal(unanswered, false)).toBe(true);
        screen.show(unanswered);
        expect(time.nextIn()).toBe(POLL_MS);

        // The study and the reveal end at their moments, on the screen too.
        expect(waitsForSignal(at('study'), false)).toBe(false);
        expect(waitsForSignal(at('reveal'), false)).toBe(false);

        // Hearing the channel, it still asks every 3 s: a socket can be open and deaf.
        screen.listening(true);
        expect(time.nextIn()).toBe(WAIT_POLL_MS);
    });

    it('asks every 3 s in the lobby and while it waits for the others, even while it hears the channel', () => {
        // A socket open and deaf for 20 s left a phone on the question it had answered
        // while the room closed it and played rounds 3 and 4 without it (rooms-flow
        // check, 2026-11-18): only a state tells such a page of the early close.
        const time = fakeTime();
        const { follow: room, refresh } = follow(time);
        room.listening(true);

        const answered = at('answer');
        expect(waitsForOthers(answered)).toBe(true);
        room.show(answered);
        expect(time.nextIn()).toBe(WAIT_POLL_MS);
        time.advance(WAIT_POLL_MS);
        expect(refresh).toHaveBeenCalledTimes(1);
        // Within the 3 s reveal: the page reads in time to be waited for in the next round.
        expect(WAIT_POLL_MS).toBeLessThanOrEqual(3000);

        // Not yet answered, it asks at the bell; the reveal waits for its moment.
        const unanswered = at('answer', { answered: false, answered_count: 0 });
        expect(waitsForOthers(unanswered)).toBe(false);
        expect(waitsForSignal(unanswered)).toBe(false);
        room.show(unanswered);
        expect(time.nextIn()).toBe(T.bell - (OFFSET + WAIT_POLL_MS) + LATE_MS);
        expect(waitsForSignal(at('reveal'))).toBe(false);

        // The lobby and a game that is over: a socket deaf in the lobby left a
        // phone there while a fast game played its first three rounds (rooms-flow
        // check, 2026-11-18). Asked every 3 s, it learns of a start within the
        // 3 s countdown.
        expect(waitsForOthers(state('lobby'))).toBe(false);
        expect(waitsForSignal(state('lobby'))).toBe(true);
        expect(waitsForSignal(state('over'))).toBe(true);
        const before = refresh.mock.calls.length;
        room.show(state('lobby'));

        for (let i = 0; i < 4; i++) {
            expect(time.nextIn()).toBe(WAIT_POLL_MS);
            time.advance(WAIT_POLL_MS);
            room.show(state('lobby'));
        }

        expect(refresh).toHaveBeenCalledTimes(before + 4);
        room.show(state('over'));
        expect(time.nextIn()).toBe(WAIT_POLL_MS);
    });

    it('asks again when a request fails, whoever made it, a little later each time', () => {
        const time = fakeTime();
        const { follow: room, refresh } = follow(time);

        // The lobby with the channel heard: a signal, or a poll every 3 s.
        room.listening(true);
        room.show(state('lobby'));
        expect(time.nextIn()).toBe(WAIT_POLL_MS);

        // The fetch a signal started fails (the server, the limiter, the network):
        // the page asks again rather than wait for another signal.
        const waits: number[] = [];

        for (let i = 0; i < 6; i++) {
            room.failed();
            waits.push(time.nextIn());
            time.advance(time.nextIn());
        }

        expect(waits).toEqual([...AGAIN_MS, AGAIN_MS[4]]);
        expect(refresh).toHaveBeenCalledTimes(6);

        // A state shown ends it and starts the count again.
        room.show(at('countdown', { opens_ms: OFFSET + time.local() + 3000 }));
        expect(time.nextIn()).toBe(3000 + LATE_MS);
        room.failed();
        expect(time.nextIn()).toBe(AGAIN_MS[0]);

        // A state shown in the lobby ends it too: back to the lobby's 3 s poll.
        room.show(state('lobby'));
        expect(time.nextIn()).toBe(WAIT_POLL_MS);
    });

    it('stops', () => {
        const time = fakeTime();
        const { follow: room } = follow(time);
        room.show(at('study'));
        expect(time.pending.size).toBe(1);

        room.stop();
        expect(time.pending.size).toBe(0);
        room.show(at('study'));
        room.failed();
        room.listening(false);
        expect(time.pending.size).toBe(0);
    });
});

describe('the time bar', () => {
    it('draws the same bar on a slow clock', () => {
        // Two phones fetch the same state at the same real moment; the second one's clock is 37 s behind.
        const fast = fakeTime(1000);
        const slow = fakeTime(1000 - 37_000);
        const clocks = [fast, slow].map((time) => {
            const clock = serverClock(time.local);
            // Sent at the real moment 1000, answered 80 ms later; the server read its clock in between.
            const sent = time.local();
            time.advance(80);
            clock.observe(T.answerFrom + 2000, sent, time.local());

            return { time, clock };
        });
        const round = at('answer').round;
        expect(round).not.toBeNull();

        const stretch = barStretch(round!);
        expect(stretch).toEqual({ from: T.answerFrom, to: T.bell });

        const bars = () =>
            clocks.map(({ clock }) =>
                timeLeft(stretch!.from, stretch!.to, clock.now()),
            );

        // 2 s of the 10 s answer have gone (and half the request's 80 ms): the same on both.
        const [first, second] = bars();
        expect(first).toBeCloseTo(1 - 2040 / 10_000, 9);
        expect(second).toBe(first);

        // Later, still the same; the device's own clock would have drawn the slow phone's bar 37 s off.
        for (const { time } of clocks) {
            time.advance(5000);
        }
        const [later, laterSlow] = bars();
        expect(later).toBeCloseTo(1 - 7040 / 10_000, 9);
        expect(laterSlow).toBe(later);
        expect(
            timeLeft(stretch!.from, stretch!.to, slow.local() + OFFSET),
        ).not.toBeCloseTo(later, 2);
    });

    it('is full before the first state and empty after its end, never beyond', () => {
        expect(timeLeft(1000, 2000, null)).toBe(1);
        expect(timeLeft(1000, 2000, 500)).toBe(1);
        expect(timeLeft(1000, 2000, 1500)).toBe(0.5);
        expect(timeLeft(1000, 2000, 2500)).toBe(0);
        expect(timeLeft(2000, 2000, 2000)).toBe(0);
    });

    it('measures the study, the answer and the wait to the next round', () => {
        expect(barStretch(at('countdown').round!)).toBeNull();
        expect(barStretch(at('study').round!)).toEqual({
            from: T.opens,
            to: T.answerFrom,
        });
        expect(barStretch(at('reveal').round!)).toEqual({
            from: T.bell,
            to: T.next,
        });
    });
});

describe('a domino game’s moments of time', () => {
    it('ends the countdown at the first hand, a turn at its flag, a pause at the next hand (ADR 038 decision 40)', () => {
        const ends: Record<string, number | null> = {
            chosen: null,
            countdown: 1795534802000,
            play: 1795534838000,
            screen: 1795534838000,
            watch: 1795534838000,
            away: 1795534836000,
            drew: 1795534863000,
            opener: 1795534863000,
            'sheet.blocked': 1795534833000,
            'sheet.finished': 1795534837000,
            'sheet.tied': 1795534836000,
            'sheet.void': 1795534813000,
            over: null,
            stopped: null,
        };

        expect(
            Object.keys(fixtures).filter((key) =>
                key.includes('state.domino.'),
            ),
        ).toHaveLength(14);

        for (const [name, moment] of Object.entries(ends)) {
            const fixture = state(`domino.${name}`);

            expect(phaseEnds(fixture), name).toBe(moment);
            // A live game waits for every seat's play and the server's for a seat; before and after it, only a start.
            expect(waitsForOthers(fixture), name).toBe(
                fixture.game?.phase === 'live',
            );
            expect(waitsForSignal(fixture), name).toBe(true);
        }
    });
});
