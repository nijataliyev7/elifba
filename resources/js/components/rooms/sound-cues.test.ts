import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomState as State } from '@/rooms/contract';
import type { RoomSpeaker } from '@/rooms/sound';
import { finalRows, finalTimeline } from './final';
import { REVEAL_MS } from './motion';
import {
    CALL_AFTER_CHEER_MS,
    CALL_GAP_MS,
    callsReady,
    carriesRoom,
    CLOCK_MS,
    FANFARE_MS,
    holdsSeat,
    LEAD_MS,
    MAX_NAMES,
    readyCallAt,
    roomCues,
    sameMoment,
    STALE_MS,
    tauntsLive,
} from './sound-cues';
import type { RoomCue, RoomEar } from './sound-cues';
import { standingGroups } from './standing-groups';
import { TAUNT_REACTION_MS } from './taunts';

/*
 * When a room game sounds (ADR 025 "Sound", ADR 028, docs/frontend.md rule
 * 15 "Sound"): a cue plays when its moment begins and the page saw it
 * begin, once however often the state comes again, never for the moment a
 * page opened on; the countdown and the clock are one file each, placed to
 * end at `opens_ms` and `bell_ms`; the screen plays the room's moments and
 * the same whatever anyone answered; a phone plays its own answer sent,
 * one sound for every answer, and its verdict only from the reveal — and,
 * in a room without a screen (`room.screen` false, ADR 028 as amended
 * 2026-11-19), the room's moments as the screen would, but never the
 * winner's cheer. After the final's fanfare the screen alone cheers the
 * first place (ADR 030). A taunt is said by the devices that draw its
 * reaction — the target's phone and the screen — once, as the page saw it
 * arrive (ADR 029 as amended 2026-11-19). The fixtures' rooms have no
 * screen; a phone's own tests put one in (`screened()`).
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): State {
    return RoomState.parse(
        fixtures[`../../../../tests/Fixtures/rooms/state.${name}.json`],
    );
}

type LiveState = Extract<State, { round: object }>;

function live(name: string): LiveState {
    const found = state(name);

    if (found.round === null || found.game === null) {
        throw new Error(`${name} has no round`);
    }

    return found as LiveState;
}

const lobby = state('lobby');
const countdown = live('countdown');
const study = live('study');
const answered = live('answer');
const reveal = live('reveal');
const over = state('over');

/** The question before the viewer answered. */
const answer = {
    ...answered,
    round: { ...answered.round, answered: false, answered_count: 0 },
} as State;

/** The same reveal with the viewer's answer wrong, or without an answer. */
function revealWith(mine: 'right' | 'wrong' | 'none'): State {
    const base = reveal.round;

    if (base.phase !== 'reveal') {
        throw new Error('a reveal is needed');
    }

    return {
        ...reveal,
        round: {
            ...base,
            reveal: {
                ...base.reveal,
                mine:
                    mine === 'none' || base.reveal.mine === null
                        ? null
                        : {
                              ...base.reveal.mine,
                              correct: mine === 'right',
                              points: mine === 'right' ? 19 : 0,
                          },
            },
        },
    } as State;
}

const opens = countdown.round.opens_ms;
const answerFrom = answered.round.answer_from_ms;
const bell = answered.round.bell_ms;
const closes = reveal.round.closes_ms ?? 0;

/** Each step of one round as a page hears it: the state and the server's clock then. */
function game(mine: 'right' | 'wrong' | 'none'): RoomEar[] {
    const shown = revealWith(mine);
    // Without an answer the viewer's question stays unanswered.
    const sent = mine === 'none' ? answer : answered;

    return [
        { state: lobby, now: opens - 5000 },
        { state: countdown, now: opens - 2500 },
        { state: countdown, now: opens - 2300 },
        { state: countdown, now: opens - 1500 },
        { state: countdown, now: opens - 500 },
        { state: countdown, now: opens },
        { state: study, now: study.round.opens_ms + 80 },
        { state: study, now: study.round.opens_ms + 900 },
        { state: answer, now: answerFrom + 60 },
        { state: answer, now: answerFrom + 1200 },
        { state: sent, now: answerFrom + 2400 },
        { state: sent, now: answerFrom + 3000 },
        { state: sent, now: bell - 5400 },
        { state: sent, now: bell - 2900 },
        { state: sent, now: bell - 1900 },
        { state: sent, now: bell - 900 },
        { state: sent, now: bell - 100 },
        { state: shown, now: closes + 120 },
        { state: shown, now: closes + 600 },
        { state: shown, now: closes + 3000 },
        { state: over, now: over.server_ms + 50 },
        { state: over, now: over.server_ms + 3000 },
    ];
}

/** The same state in a room whose big screen is on (`room.screen`), or off. */
function withScreen(source: State, on = true): State {
    return { ...source, room: { ...source.room, screen: on } } as State;
}

/** The same ears in a room with its big screen on. */
function screened(ears: RoomEar[]): RoomEar[] {
    return ears.map((ear) => ({
        ...ear,
        state: ear.state === null ? null : withScreen(ear.state),
    }));
}

/** Every cue a page plays along `ears`, with the step it plays at. */
function heard(
    ears: RoomEar[],
    speaker: RoomSpeaker,
): { step: number; cue: RoomCue }[] {
    const played: { step: number; cue: RoomCue }[] = [];

    ears.forEach((ear, step) => {
        const previous = step === 0 ? null : ears[step - 1];

        for (const cue of roomCues(previous, ear, speaker)) {
            played.push({ step, cue });
        }
    });

    return played;
}

const names = (played: { cue: RoomCue }[]) =>
    played.map(({ cue }) => cue.sound);

const overTimeline = finalTimeline(standingGroups(over.ranking, []), 'screen');
const winnerAt = overTimeline.winnerAt ?? -1;
/** The fixture's final has a first and a second place, then two players without points. */
const secondAt = overTimeline.opensAt[1];
/** Who holds the fixture's first place: the name said after the fanfare. */
const winner = over.ranking[0].player_id;

describe('roomCues on the screen', () => {
    it("plays each of the room's moments once, however often the state comes again", () => {
        const played = heard(game('right'), 'screen');

        expect(played.map(({ step, cue }) => [step, cue])).toEqual([
            [1, { sound: 'countdown', at: 2500, ends: true }],
            [6, { sound: 'study', at: 0 }],
            [8, { sound: 'question', at: 0 }],
            [12, { sound: 'clock', at: 5400, ends: true }],
            [17, { sound: 'close', at: 0 }],
            [17, { sound: 'ranking', at: REVEAL_MS.ranking }],
            [20, { sound: 'place', at: secondAt }],
            [20, { sound: 'roll', at: winnerAt, ends: true }],
            [20, { sound: 'fanfare', at: winnerAt }],
            [
                20,
                {
                    sound: 'cheer',
                    at: winnerAt + FANFARE_MS,
                    players: [winner],
                    many: false,
                },
            ],
        ]);
    });

    it('plays the same whatever anyone answered: it has no viewer', () => {
        const right = heard(game('right'), 'screen');

        expect(heard(game('wrong'), 'screen')).toEqual(right);
        expect(heard(game('none'), 'screen')).toEqual(right);
    });

    it('rolls only towards a first place, and plays nothing for a final nobody scored in', () => {
        const nobody = {
            ...over,
            ranking: over.ranking.map((row) => ({
                ...row,
                points: 0,
                place: null,
            })),
        } as State;

        expect(
            roomCues(
                { state: reveal, now: closes + 100 },
                { state: nobody, now: closes + 5100 },
                'screen',
            ),
        ).toEqual([]);
        expect(winnerAt).toBeGreaterThan(0);
    });

    it('runs the clock once, as one file ending at the bell, heard ahead of its last five seconds', () => {
        const clocks = heard(game('right'), 'screen').filter(
            ({ cue }) => cue.sound === 'clock',
        );

        expect(clocks).toHaveLength(1);
        expect(game('right')[clocks[0].step].now).toBe(bell - 5400);
        expect(clocks[0].cue).toEqual({ sound: 'clock', at: 5400, ends: true });
        expect(CLOCK_MS + LEAD_MS).toBeGreaterThanOrEqual(5400);

        // Not yet, more than CLOCK_MS + LEAD_MS before the bell.
        expect(
            roomCues(
                { state: answer, now: bell - 9000 },
                { state: answer, now: bell - CLOCK_MS - LEAD_MS - 1 },
                'screen',
            ),
        ).toEqual([]);
    });

    it('places the countdown to end where round 0 opens, from its middle when it is heard late', () => {
        expect(
            roomCues(
                { state: lobby, now: opens - 4000 },
                { state: countdown, now: opens - 1200 },
                'screen',
            ),
        ).toEqual([{ sound: 'countdown', at: 1200, ends: true }]);
        // Without the server's clock there is no countdown to place.
        expect(
            roomCues(
                { state: lobby, now: null },
                { state: countdown, now: null },
                'screen',
            ),
        ).toEqual([]);
    });

    it('starts the game with «go»: round 0 opens with it in place of its study or its question', () => {
        const first = <T extends LiveState>(
            source: T,
            studied: boolean,
        ): State =>
            ({
                ...source,
                round: {
                    ...source.round,
                    index: 0,
                    answer_from_ms: studied
                        ? source.round.answer_from_ms
                        : source.round.opens_ms,
                },
            }) as State;
        const from = { state: countdown, now: opens - 100 };

        // A memory game: round 0's study is the start; its question is a question.
        expect(
            roomCues(
                from,
                { state: first(study, true), now: opens + 80 },
                'screen',
            ),
        ).toEqual([{ sound: 'go', at: 0 }]);
        expect(
            roomCues(
                { state: first(study, true), now: opens + 900 },
                {
                    state: first(answer as LiveState, true),
                    now: answerFrom + 60,
                },
                'screen',
            ),
        ).toEqual([{ sound: 'question', at: 0 }]);

        // A game without a study: round 0's question is the start.
        const opening = first(answer as LiveState, false);

        expect(
            roomCues(
                from,
                { state: opening, now: (opening.round?.opens_ms ?? 0) + 80 },
                'screen',
            ),
        ).toEqual([{ sound: 'go', at: 0 }]);
    });

    it('plays the clock with the question when the answer is shorter than the clock', () => {
        const short = {
            ...answer,
            round: { ...answer.round, bell_ms: answerFrom + 4000 },
        } as State;

        expect(
            roomCues(
                { state: study, now: answerFrom - 100 },
                { state: short, now: answerFrom + 60 },
                'screen',
            ),
        ).toEqual([
            { sound: 'question', at: 0 },
            { sound: 'clock', at: 3940, ends: true },
        ]);
    });

    it('ends the clock with its moment when everybody answered: the round closes early for the whole room', () => {
        const early = {
            ...reveal,
            round: { ...reveal.round, closes_ms: bell - 2000 },
        } as State;
        const before = { state: answered, now: bell - 2100 };
        const after = { state: early, now: bell - 1900 };

        expect(sameMoment(before, after, 'screen')).toBe(false);
        expect(
            roomCues(before, after, 'screen').map(({ sound }) => sound),
        ).toEqual(['close', 'ranking']);
    });

    it('hits the third and the second place as they open, then rolls to the first', () => {
        const five = {
            ...over,
            ranking: [
                { player_id: 'p1', points: 90, place: 1 },
                { player_id: 'p2', points: 70, place: 2 },
                { player_id: 'p3', points: 60, place: 3 },
                { player_id: 'p4', points: 30, place: 4 },
                { player_id: 'p5', points: 0, place: null },
            ],
        } as State;
        const timeline = finalTimeline(
            standingGroups(five.ranking, []),
            'screen',
        );

        expect(
            roomCues(
                { state: reveal, now: closes + 100 },
                { state: five, now: closes + 3100 },
                'screen',
            ),
        ).toEqual([
            { sound: 'place', at: timeline.opensAt[2] },
            { sound: 'place', at: timeline.opensAt[1] },
            { sound: 'roll', at: timeline.winnerAt, ends: true },
            { sound: 'fanfare', at: timeline.winnerAt },
            {
                sound: 'cheer',
                at: (timeline.winnerAt ?? 0) + FANFARE_MS,
                players: ['p1'],
                many: false,
            },
        ]);
    });

    it('plays «join» between games when a player appears, once a state, never during a game or for a player who leaves or comes back', () => {
        const extra = {
            id: '01kaqrx9zz9zz9zz9zz9zz9zz9',
            name: 'Yeni',
            present: true,
            host: false,
        };
        const joined = (source: State, count = 1): State =>
            ({
                ...source,
                players: [
                    ...source.players,
                    ...Array.from({ length: count }, (_, n) => ({
                        ...extra,
                        id: extra.id.slice(0, -1) + String(n),
                    })),
                ],
            }) as State;
        const away = {
            ...lobby,
            players: lobby.players.map((player, n) =>
                n === 1 ? { ...player, present: false } : player,
            ),
        } as State;

        expect(
            roomCues(
                { state: lobby, now: 1 },
                { state: joined(lobby, 3), now: 2 },
                'screen',
            ),
        ).toEqual([{ sound: 'join', at: 0 }]);
        expect(
            roomCues(
                { state: over, now: 1 },
                { state: joined(over), now: 2 },
                'screen',
            ),
        ).toEqual([{ sound: 'join', at: 0 }]);
        expect(
            roomCues(
                { state: joined(lobby), now: 1 },
                { state: lobby, now: 2 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: lobby, now: 1 },
                { state: away, now: 2 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: away, now: 1 },
                { state: lobby, now: 2 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: answered, now: answerFrom + 2000 },
                { state: joined(answered), now: answerFrom + 2100 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: withScreen(lobby), now: 1 },
                { state: withScreen(joined(lobby)), now: 2 },
                'phone',
            ),
        ).toEqual([]);
    });
});

describe("roomCues and the winner's cheer (ADR 030)", () => {
    /** The fixture's final with `first` holding the first place together, in this order, and the rest after them. */
    function sharedFirst(first: string[]): State {
        return {
            ...over,
            ranking: [
                ...first.map((player_id) => ({
                    player_id,
                    points: 74,
                    place: 1,
                })),
                { player_id: 'p9', points: 20, place: 2 },
            ],
        } as State;
    }

    const finalOf = (shown: State, speaker: RoomSpeaker = 'screen') =>
        roomCues(
            { state: reveal, now: closes + 100 },
            { state: shown, now: closes + 3100 },
            speaker,
        );

    it('cheers the winner after the fanfare: the first name, then «Əla oynadın!»', () => {
        const cues = finalOf(over);
        const fanfare = cues.findIndex(({ sound }) => sound === 'fanfare');
        const said = cues.findIndex(({ sound }) => sound === 'cheer');

        expect(said).toBe(fanfare + 1);
        // One winner: the singular phrase.
        expect(cues[said]).toEqual({
            sound: 'cheer',
            at: winnerAt + FANFARE_MS,
            players: [winner],
            many: false,
        });
        expect(cues[said].at).toBeGreaterThan(cues[fanfare].at);
        // The fanfare is the README's 2.30 s (scripts/room-sounds/README.md).
        expect(FANFARE_MS).toBe(2300);
    });

    it("names two or three who share the first place in the state's order, then «Əla oynadınız!», and none of four", () => {
        const cheered = (first: string[]) =>
            finalOf(sharedFirst(first)).find(({ sound }) => sound === 'cheer');

        // The server's order, never re-sorted; a shared place, the plural phrase.
        expect(cheered(['p2', 'p1'])).toMatchObject({
            players: ['p2', 'p1'],
            many: true,
        });
        expect(cheered(['p3', 'p1', 'p2'])).toMatchObject({
            players: ['p3', 'p1', 'p2'],
            many: true,
        });
        expect(MAX_NAMES).toBe(3);

        // Four share it: nobody is named, and «Əla oynadınız!» is said alone.
        expect(cheered(['p1', 'p2', 'p3', 'p4'])).toMatchObject({
            players: [],
            many: true,
        });
    });

    it('says nothing on a reload of the final, or when the state comes again', () => {
        // The page opened on the final: the first thing it hears.
        expect(roomCues(null, { state: over, now: 1000 }, 'screen')).toEqual(
            [],
        );
        // The final fetched again, a poll or a `room.changed`, is the same moment.
        expect(
            roomCues(
                { state: over, now: 1000 },
                { state: over, now: 4000 },
                'screen',
            ),
        ).toEqual([]);
    });

    it('cheers nobody when nobody holds the first place', () => {
        const nobody = {
            ...over,
            ranking: over.ranking.map((row) => ({
                ...row,
                points: 0,
                place: null,
            })),
        } as State;

        expect(finalOf(nobody)).toEqual([]);
    });

    it("cheers on a phone only in a room without a screen, at the phone final's pace", () => {
        const mine = {
            ...over,
            me: { ...over.me, player_id: winner },
        } as State;

        // With the screen on, the screen alone cheers: not even the winner's own phone.
        expect(finalOf(withScreen(over), 'phone')).toEqual([]);
        expect(finalOf(withScreen(mine), 'phone')).toEqual([]);
        expect(
            names(heard(screened(game('right')), 'phone')).filter(
                (sound) => sound === 'cheer',
            ),
        ).toEqual([]);

        // Without one, every phone cheers, after its own fanfare (ADR 030 as amended 2026-11-19).
        const phoneAt =
            finalTimeline(standingGroups(over.ranking, []), 'phone').winnerAt ??
            -1;

        expect(phoneAt).toBeLessThan(winnerAt);

        for (const final of [over, mine]) {
            const played = finalOf(final, 'phone');

            expect(played).toContainEqual({ sound: 'fanfare', at: phoneAt });
            expect(played).toContainEqual({
                sound: 'cheer',
                at: phoneAt + FANFARE_MS,
                players: [winner],
                many: false,
            });
        }
    });
});

describe('roomCues on a phone in a room with its screen', () => {
    it('plays the answer sent once, and the verdict only from the reveal', () => {
        expect(
            heard(screened(game('right')), 'phone').map(({ step, cue }) => [
                step,
                cue,
            ]),
        ).toEqual([
            [10, { sound: 'sent', at: 0 }],
            [17, { sound: 'right', at: 0 }],
        ]);
        expect(names(heard(screened(game('wrong')), 'phone'))).toEqual([
            'sent',
            'wrong',
        ]);
    });

    it("plays none of the room's moments", () => {
        const played = names(heard(screened(game('right')), 'phone'));

        for (const room of [
            'countdown',
            'go',
            'study',
            'question',
            'clock',
            'close',
            'ranking',
            'place',
            'roll',
            'fanfare',
            'join',
        ]) {
            expect(played).not.toContain(room);
        }
    });

    it('plays nothing at a reveal without an answer, or for a host who does not play', () => {
        expect(names(heard(screened(game('none')), 'phone'))).toEqual([]);

        const watching = (ear: RoomEar): RoomEar => ({
            ...ear,
            state:
                ear.state === null
                    ? null
                    : ({
                          ...withScreen(ear.state),
                          me: { player_id: null, host: true },
                      } as State),
        });

        expect(
            roomCues(
                watching({ state: answered, now: bell - 100 }),
                watching({ state: revealWith('right'), now: closes + 100 }),
                'phone',
            ),
        ).toEqual([]);
    });

    it('plays the same before the reveal for a right and a wrong answer', () => {
        const before = (mine: 'right' | 'wrong') =>
            heard(game(mine), 'phone').filter(({ step }) => step < 17);

        expect(before('wrong')).toEqual(before('right'));
        expect(
            heard(screened(game('wrong')), 'phone').filter(
                ({ step }) => step < 17,
            ),
        ).toEqual(
            heard(screened(game('right')), 'phone').filter(
                ({ step }) => step < 17,
            ),
        );
    });
});

describe('roomCues on a phone in a room without a screen', () => {
    it('carries the room only while it is open and its screen is off', () => {
        expect(carriesRoom(lobby)).toBe(true);
        expect(carriesRoom(withScreen(lobby))).toBe(false);
        expect(
            carriesRoom({
                ...lobby,
                room: { ...lobby.room, status: 'closed', code: null },
            } as State),
        ).toBe(false);
        expect(carriesRoom(null)).toBe(false);
    });

    it("plays every one of the room's moments as the screen does, its final at a phone's pace, and its own cues too", () => {
        // The final is the steps from `over` on: there the pace differs.
        const final = game('right').findIndex(
            ({ state }) => state?.game?.phase === 'over',
        );

        for (const mine of ['right', 'wrong', 'none'] as const) {
            const merged = [
                ...heard(game(mine), 'screen'),
                ...heard(screened(game(mine)), 'phone'),
            ]
                .filter(({ step }) => step < final)
                .sort((a, b) => a.step - b.step);

            expect(
                heard(game(mine), 'phone').filter(({ step }) => step < final),
            ).toEqual(merged);
        }

        // The final, at a phone's pace: the places, the roll and the fanfare where the phone's final opens them, then the cheer.
        const timeline = finalTimeline(
            standingGroups(over.ranking, []),
            'phone',
        );
        const finalPlayed = heard(game('right'), 'phone')
            .filter(({ step }) => step >= final)
            .map(({ cue }) => cue);

        expect(finalPlayed).toContainEqual({
            sound: 'fanfare',
            at: timeline.winnerAt,
        });
        expect(finalPlayed).toContainEqual({
            sound: 'roll',
            at: timeline.winnerAt,
            ends: true,
        });
        expect(names(finalPlayed.map((cue) => ({ cue })))).toContain('cheer');

        expect(names(heard(game('right'), 'phone'))).toEqual(
            expect.arrayContaining([
                'countdown',
                'clock',
                'close',
                'ranking',
                'fanfare',
                'sent',
                'right',
            ]),
        );
    });

    it('plays «join» in the lobby', () => {
        const joined = {
            ...lobby,
            players: [
                ...lobby.players,
                {
                    id: '01kaqrx9zz9zz9zz9zz9zz9zz9',
                    name: 'Yeni',
                    present: true,
                    host: false,
                },
            ],
        } as State;

        expect(
            roomCues(
                { state: lobby, now: 1 },
                { state: joined, now: 2 },
                'phone',
            ),
        ).toEqual([{ sound: 'join', at: 0 }]);
    });

    it('runs the clock and plays the answer sent when both come in one moment', () => {
        expect(
            roomCues(
                { state: answer, now: bell - 6000 },
                { state: answered, now: bell - 5400 },
                'phone',
            ),
        ).toEqual([
            { sound: 'clock', at: 5400, ends: true },
            { sound: 'sent', at: 0 },
        ]);
    });

    it('stops carrying once the screen comes on, and carries again when it goes', () => {
        expect(
            roomCues(
                { state: withScreen(lobby), now: opens - 5000 },
                { state: withScreen(countdown), now: opens - 2500 },
                'phone',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: withScreen(lobby), now: opens - 5000 },
                { state: countdown, now: opens - 2500 },
                'phone',
            ),
        ).toEqual([{ sound: 'countdown', at: 2500, ends: true }]);
    });
});

describe('roomCues and the call for «Hazıram» (ADR 035 as amended 2026-11-21)', () => {
    /** The fixture's final with the viewer — a present player who is not the host — not yet marked. */
    const unmarked = {
        ...over,
        players: over.players.map((player) =>
            player.id === over.me.player_id
                ? { ...player, ready: false }
                : player,
        ),
    } as State;
    const before: RoomEar = {
        state: revealWith('right'),
        now: closes + 3000,
    };
    const end: RoomEar = { state: unmarked, now: over.server_ms + 50 };
    const screenedEar = (ear: RoomEar): RoomEar => ({
        ...ear,
        state: ear.state === null ? null : withScreen(ear.state),
    });
    const calls = (cues: RoomCue[]) =>
        cues.filter((cue) => cue.sound === 'offer');

    it("comes after the room's show: the final at its pace, the fanfare and the cheer", () => {
        const screenAt = readyCallAt(over, 'screen');
        const phone = finalTimeline(standingGroups(over.ranking, []), 'phone');

        expect(screenAt).toBe(winnerAt + FANFARE_MS + CALL_AFTER_CHEER_MS);
        expect(screenAt).toBeGreaterThanOrEqual(overTimeline.endsAt);
        expect(readyCallAt(over, 'phone')).toBe(
            (phone.winnerAt ?? -1) + FANFARE_MS + CALL_AFTER_CHEER_MS,
        );

        // Nobody in first place: a little after the last place opened.
        const nobody = {
            ...over,
            ranking: over.ranking.map((row) => ({
                ...row,
                points: 0,
                place: null,
            })),
        } as State;
        const empty = finalTimeline(
            standingGroups(nobody.ranking, []),
            'screen',
        );

        expect(empty.winnerAt).toBeNull();
        expect(readyCallAt(nobody, 'screen')).toBe(empty.endsAt + CALL_GAP_MS);
    });

    it("chimes once on a player's phone that saw the game end, with a screen or without one", () => {
        expect(
            calls(roomCues(screenedEar(before), screenedEar(end), 'phone')),
        ).toEqual([{ sound: 'offer', at: readyCallAt(unmarked, 'screen') }]);

        // Without a screen the phone plays the room's final itself, then the call at its own pace.
        const carrying = roomCues(before, end, 'phone');

        expect(names(carrying.map((cue) => ({ cue })))).toContain('fanfare');
        expect(calls(carrying)).toEqual([
            { sound: 'offer', at: readyCallAt(unmarked, 'phone') },
        ]);

        // The state fetched again is the same moment: no second chime.
        expect(
            calls(
                roomCues(
                    screenedEar(end),
                    screenedEar({ ...end, now: end.now! + 4000 }),
                    'phone',
                ),
            ),
        ).toEqual([]);
    });

    it('calls nobody but a present, unmarked player of an open room who is not the host, and never on the screen or a page opened on the final', () => {
        expect(callsReady(unmarked)).toBe(true);
        // The fixture's viewer has marked already.
        expect(callsReady(over)).toBe(false);
        expect(
            calls(
                roomCues(
                    screenedEar(before),
                    screenedEar({ ...end, state: over }),
                    'phone',
                ),
            ),
        ).toEqual([]);

        const host = {
            ...unmarked,
            me: { player_id: unmarked.players[0].id, host: true },
        } as State;
        const watching = {
            ...unmarked,
            me: { player_id: null, host: true },
        } as State;
        const closed = {
            ...unmarked,
            room: { ...unmarked.room, status: 'closed', code: null },
            voice: null,
        } as State;

        for (const other of [host, watching, closed]) {
            expect(callsReady(other)).toBe(false);
            expect(
                calls(
                    roomCues(
                        screenedEar(before),
                        screenedEar({ ...end, state: other }),
                        'phone',
                    ),
                ),
            ).toEqual([]);
        }

        expect(calls(roomCues(null, screenedEar(end), 'phone'))).toEqual([]);
        expect(
            calls(roomCues(screenedEar(before), screenedEar(end), 'screen')),
        ).toEqual([]);
        expect(callsReady(lobby)).toBe(false);
    });
});

describe('roomCues and the moment a page opened on', () => {
    it('plays nothing for the first thing a page hears', () => {
        for (const speaker of ['screen', 'phone'] as const) {
            for (const ear of [...game('right'), ...game('wrong')]) {
                expect(roomCues(null, ear, speaker)).toEqual([]);
            }
        }
    });

    it('replays nothing after a reload mid-phase, and plays what begins after it', () => {
        for (const speaker of ['screen', 'phone'] as const) {
            const steps = game('right');

            // Opened at each step: the moment it opened on stays silent, however often it comes.
            steps.forEach((ear, step) => {
                const again = { ...ear, now: (ear.now ?? 0) + 50 };

                expect(
                    heard([ear, again, again], speaker),
                    `${speaker} ${step}`,
                ).toEqual([]);
            });
        }

        // Opened in the countdown: it is one moment, silent to the end.
        expect(
            heard(
                [
                    { state: countdown, now: opens - 1500 },
                    { state: countdown, now: opens - 1400 },
                    { state: countdown, now: opens - 500 },
                ],
                'screen',
            ),
        ).toEqual([]);

        // Opened in an answer's last seconds: the clock it opened in is not played.
        expect(
            heard(
                [
                    { state: answered, now: bell - 3000 },
                    { state: answered, now: bell - 2800 },
                    { state: answered, now: bell - 100 },
                ],
                'screen',
            ),
        ).toEqual([]);

        // Opened earlier in the answer: the clock begins after it, so it plays.
        expect(
            names(
                heard(
                    [
                        { state: answered, now: bell - 9000 },
                        { state: answered, now: bell - 5300 },
                    ],
                    'screen',
                ),
            ),
        ).toEqual(['clock']);

        // Opened on the final: no roll and no fanfare.
        expect(
            heard(
                [
                    { state: over, now: 1 },
                    { state: over, now: 2 },
                ],
                'screen',
            ),
        ).toEqual([]);

        // Opened after the answer was sent: no «sent» again.
        expect(
            heard(
                [
                    { state: answered, now: answerFrom + 3000 },
                    { state: answered, now: answerFrom + 6000 },
                ],
                'phone',
            ),
        ).toEqual([]);
    });

    it('announces no moment heard long after it began', () => {
        expect(
            roomCues(
                { state: answered, now: bell - 100 },
                { state: reveal, now: closes + STALE_MS + 1 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: answered, now: bell - 100 },
                { state: reveal, now: closes + STALE_MS + 1 },
                'phone',
            ),
        ).toEqual([]);
        expect(
            roomCues(
                { state: withScreen(answered), now: bell - 100 },
                { state: withScreen(reveal), now: closes + STALE_MS },
                'phone',
            ),
        ).toEqual([{ sound: 'right', at: 0 }]);
    });
});

describe('roomCues before the reveal', () => {
    /** The state with its round watched: every property a cue reads of it is recorded. */
    function watched(source: State, reads: Set<string>): State {
        if (source.round === null) {
            return source;
        }

        const round = new Proxy(source.round, {
            get: (target, property, receiver) => {
                reads.add(String(property));

                return Reflect.get(target, property, receiver);
            },
        });

        return { ...source, round } as State;
    }

    it("reads no verdict, no solution and no one's result before the reveal", () => {
        for (const speaker of ['screen', 'phone'] as const) {
            const reads = new Set<string>();
            const ears = game('right')
                .filter(({ state }) => state?.round?.phase !== 'reveal')
                .map((ear) => ({
                    ...ear,
                    state:
                        ear.state === null ? null : watched(ear.state, reads),
                }));

            heard(ears, speaker);

            expect(reads.has('phase'), speaker).toBe(true);
            expect(reads.has('reveal'), speaker).toBe(false);
        }
    });

    it("never reads the viewer's verdict on the screen, even in the reveal", () => {
        const base = revealWith('right');

        if (base.round?.phase !== 'reveal') {
            throw new Error('a reveal is needed');
        }

        const secret = {
            ...base,
            round: {
                ...base.round,
                reveal: {
                    ...base.round.reveal,
                    get mine(): never {
                        throw new Error('the screen read mine');
                    },
                },
            },
        } as State;

        expect(
            names(
                heard(
                    [
                        { state: answered, now: bell - 100 },
                        { state: secret, now: closes + 100 },
                    ],
                    'screen',
                ),
            ),
        ).toEqual(['close', 'ranking']);
    });
});

describe('sameMoment', () => {
    it('is the game, the round and the phase: a beat or a fetch again is the same moment', () => {
        expect(
            sameMoment(
                { state: countdown, now: opens - 2500 },
                { state: countdown, now: opens - 500 },
                'screen',
            ),
        ).toBe(true);
        expect(
            sameMoment(
                { state: answer, now: answerFrom },
                { state: answered, now: bell - 100 },
                'phone',
            ),
        ).toBe(true);
        expect(
            sameMoment(
                { state: answered, now: bell - 100 },
                { state: reveal, now: closes + 100 },
                'screen',
            ),
        ).toBe(false);
    });
});

describe("roomCues and a taunt's voice (ADR 029 as amended 2026-11-19)", () => {
    /** The target's phone with one taunt received, and the same moment just before it. */
    const taunted = live('taunted');
    const received = taunted.taunts!.received[0];
    const untaunted = {
        ...taunted,
        taunts: { ...taunted.taunts!, received: [] },
    } as State;
    /** The screen with two taunts shown, and the same moment just before them. */
    const screenTaunted = live('screen-taunted');
    const screenUntaunted = {
        ...screenTaunted,
        taunts: { ...screenTaunted.taunts!, shown: [] },
    } as State;
    const sent = received.sent_ms;

    const sayings = (played: { step: number; cue: RoomCue }[]) =>
        played.filter(({ cue }) => cue.sound === 'taunt');

    /** A page's ears as a taunt arrives: before it, as it comes, the same state again, later. */
    const arriving = (before: State, after: State, at: number): RoomEar[] => [
        { state: before, now: at - 600 },
        { state: after, now: at + 300 },
        { state: after, now: at + 500 },
        { state: after, now: at + 2000 },
    ];

    it("says a taunt once, as the page saw it arrive, on the target's phone and the screen", () => {
        // The target's phone, in a room with its screen and in one without.
        for (const [before, after] of [
            [withScreen(untaunted), withScreen(taunted)],
            [untaunted, taunted],
        ]) {
            expect(
                sayings(heard(arriving(before, after, sent), 'phone')),
            ).toEqual([
                {
                    step: 1,
                    cue: {
                        sound: 'taunt',
                        at: 0,
                        id: received.id,
                        key: 'turtle',
                        until: TAUNT_REACTION_MS - 300,
                    },
                },
            ]);
        }

        // The screen says the room's: two that came together, the older first.
        const both = arriving(
            screenUntaunted,
            screenTaunted,
            screenTaunted.taunts!.shown![1].sent_ms,
        );

        expect(
            sayings(heard(both, 'screen')).map(({ step, cue }) => [
                step,
                cue.sound === 'taunt' ? cue.key : null,
            ]),
        ).toEqual([
            [1, 'turtle'],
            [1, 'dice'],
        ]);
    });

    it('says nothing for a page opened on it, a state fetched again, the thrower, or another phone — in a room with a screen or without', () => {
        for (const room of [true, false]) {
            const target = withScreen(taunted, room);

            // Opened on it: the first thing a page hears is silent, and the same state again brings nothing.
            expect(
                sayings(
                    heard(
                        [
                            { state: target, now: sent + 200 },
                            { state: target, now: sent + 400 },
                            { state: target, now: sent + 1200 },
                        ],
                        'phone',
                    ),
                ),
            ).toEqual([]);

            // The thrower and every other phone: their state never lists it, whatever the moment.
            const other = withScreen(untaunted, room);

            expect(
                sayings(
                    heard(
                        [
                            { state: other, now: sent - 600 },
                            { state: other, now: sent + 300 },
                        ],
                        'phone',
                    ),
                ),
            ).toEqual([]);
        }

        // A phone says only its own `received`, never the room's list, even in a room without a screen.
        const carrying = {
            ...untaunted,
            taunts: {
                ...untaunted.taunts!,
                shown: screenTaunted.taunts!.shown,
            },
        } as State;

        expect(carriesRoom(carrying)).toBe(true);
        expect(
            sayings(
                heard(
                    [
                        { state: untaunted, now: sent - 600 },
                        { state: carrying, now: sent + 300 },
                    ],
                    'phone',
                ),
            ),
        ).toEqual([]);
    });

    it("says nothing while the device hides taunts, the screen's toggle is off or the host switched them off", () => {
        const phone = roomCues(
            { state: untaunted, now: sent - 600 },
            { state: taunted, now: sent + 300 },
            'phone',
            { tauntsHidden: true },
        );
        const screen = roomCues(
            { state: screenUntaunted, now: sent - 600 },
            { state: screenTaunted, now: screenTaunted.server_ms },
            'screen',
            { tauntsHidden: true },
        );

        expect(phone).toEqual([]);
        expect(screen).toEqual([]);

        // Shown again later, a taunt it hid is not said: the page saw it arrive while hidden.
        expect(
            roomCues(
                { state: taunted, now: sent + 300 },
                { state: taunted, now: sent + 500 },
                'phone',
            ),
        ).toEqual([]);

        // The host's switch off: nothing is drawn or said.
        const off = (state: State) =>
            ({ ...state, room: { ...state.room, taunts: false } }) as State;

        expect(tauntsLive(taunted)).toBe(true);
        expect(tauntsLive(off(taunted))).toBe(false);
        expect(
            roomCues(
                { state: off(untaunted), now: sent - 600 },
                { state: off(taunted), now: sent + 300 },
                'phone',
            ),
        ).toEqual([]);
        // Nor once the game is over or the room closed.
        expect(tauntsLive(over)).toBe(false);
        expect(
            tauntsLive({
                ...taunted,
                room: { ...taunted.room, status: 'closed' },
            } as State),
        ).toBe(false);
    });

    it('keeps one taunt at a time and drops one whose reaction is over', () => {
        // Heard after its reaction ended (a page that slept): not said.
        expect(
            roomCues(
                { state: untaunted, now: sent - 600 },
                { state: taunted, now: sent + TAUNT_REACTION_MS },
                'phone',
            ),
        ).toEqual([]);

        // The screen heard two at once: the older's reaction is over at server_ms, only the newer is said.
        const late = roomCues(
            { state: screenUntaunted, now: screenTaunted.server_ms - 600 },
            { state: screenTaunted, now: screenTaunted.server_ms },
            'screen',
        );

        expect(
            late.map((cue) => (cue.sound === 'taunt' ? cue.key : null)),
        ).toEqual(['dice']);
        // Each cue carries when its reaction ends: a second taunt waits for the first only until then (the player's `taunt()`).
        expect(late[0]).toMatchObject({
            until:
                screenTaunted.taunts!.shown![0].sent_ms +
                TAUNT_REACTION_MS -
                screenTaunted.server_ms,
        });
    });

    it("belongs to the taunt, not to the round's moment: the round's close never cuts it", () => {
        // A taunt arriving is no new moment: nothing of the round is cancelled for it.
        expect(
            sameMoment(
                { state: untaunted, now: sent - 600 },
                { state: taunted, now: sent + 300 },
                'phone',
            ),
        ).toBe(true);
        // The close comes with the taunt still listed: the reveal's cues, and the taunt is not said again.
        const closed = {
            ...reveal,
            taunts: taunted.taunts,
        } as State;
        const cues = roomCues(
            { state: taunted, now: sent + 300 },
            { state: closed, now: closes + 100 },
            'phone',
        );

        expect(cues.filter((cue) => cue.sound === 'taunt')).toEqual([]);
    });
});

/*
 * A match's cues (ADR 034 decision 31, docs/frontend.md rule 15 "Board
 * games" → "Sound"): the room's speaker plays the countdown ending at
 * `starts_ms`, `go`, each move the page saw arrive — `move` or `capture` as
 * the piece lands and `crown` after it — the clock in the side to move's
 * last seconds ending at its flag, and `close` then the match's own final
 * at its end; a phone plays `offer` when a draw offer arrives at its seat,
 * and never `sent`, `right` or `wrong`.
 */
describe('roomCues in a match', () => {
    type Play = Extract<NonNullable<State['match']>, { phase: 'play' }>;

    const matchCountdown = state('match.countdown');
    const matchPlay = state('match.play');
    const matchOffer = state('match.offer');
    const matchWatch = state('match.watch');
    const matchOver = state('match.over');
    const playOf = (source: State): Play => {
        if (source.match?.phase !== 'play') {
            throw new Error('a match in play is needed');
        }

        return source.match;
    };
    const starts = playOf(matchPlay).starts_ms;
    /** The same match at another ply, with its last move; the moment begins at `since_ms`. */
    const atPly = (
        source: State,
        ply: number,
        last: Play['last'],
        since: number,
    ): State =>
        ({
            ...source,
            match: {
                ...playOf(source),
                ply,
                last,
                since_ms: since,
                flag_ms: since + 290_000,
                offer: null,
            },
        }) as State;
    const begun = atPly(matchPlay, 0, null, starts);
    const quiet = atPly(
        matchPlay,
        3,
        { path: ['c3', 'd4'], captured: [], crowned: false },
        starts + 9_000,
    );
    const taken = atPly(
        matchPlay,
        3,
        { path: ['d4', 'f6', 'd8'], captured: ['e5', 'e7'], crowned: true },
        starts + 9_000,
    );

    it('counts down to the clock, then plays go, once', () => {
        const ears: RoomEar[] = [
            { state: lobby, now: starts - 5_000 },
            { state: matchCountdown, now: starts - 2_500 },
            { state: matchCountdown, now: starts - 1_500 },
            { state: begun, now: starts + 80 },
            { state: begun, now: starts + 900 },
        ];
        const played = heard(ears, 'screen');

        expect(names(played)).toEqual(['countdown', 'go']);
        expect(played[0].cue).toMatchObject({ at: 2_500, ends: true });
    });

    it('plays a move the page saw arrive as its piece lands, a capture and a crowning as such', () => {
        const step = (next: State) =>
            roomCues(
                { state: matchPlay, now: starts + 8_000 },
                { state: next, now: starts + 9_050 },
                'screen',
            );

        expect(step(quiet)).toEqual([{ sound: 'move', at: 160 }]);
        expect(step(taken)).toEqual([
            { sound: 'capture', at: 320 },
            { sound: 'crown', at: 440 },
        ]);
        // The same ply again, a page opened on it, or heard long after: silent.
        expect(
            roomCues(
                { state: taken, now: starts + 9_100 },
                { state: taken, now: starts + 9_300 },
                'screen',
            ),
        ).toEqual([]);
        expect(
            roomCues(null, { state: taken, now: starts + 9_100 }, 'screen'),
        ).toEqual([]);
        expect(
            roomCues(
                { state: matchPlay, now: starts + 8_000 },
                { state: taken, now: starts + 9_000 + STALE_MS + 1 },
                'screen',
            ),
        ).toEqual([]);
    });

    it("runs the clock in the side to move's last seconds, ending at its flag, and stops it with the move", () => {
        const flag = playOf(matchPlay).flag_ms;
        const near = { state: matchPlay, now: flag - CLOCK_MS - LEAD_MS + 100 };
        const cues = roomCues(
            { state: matchPlay, now: flag - 20_000 },
            near,
            'screen',
        );

        expect(cues).toEqual([
            { sound: 'clock', at: CLOCK_MS + LEAD_MS - 100, ends: true },
        ]);
        expect(
            sameMoment(near, { state: quiet, now: flag - 2_000 }, 'screen'),
        ).toBe(false);
    });

    it('plays the offer on the phone of the seat it is made to, and nowhere else', () => {
        const before = { state: withScreen(matchPlay), now: starts + 12_000 };
        const after = { state: withScreen(matchOffer), now: starts + 12_500 };

        expect(roomCues(before, after, 'phone')).toEqual([
            { sound: 'offer', at: 0 },
        ]);
        expect(
            roomCues(after, { ...after, now: starts + 13_000 }, 'phone'),
        ).toEqual([]);
        expect(roomCues(before, after, 'screen')).toEqual([]);
        // A watcher's phone: no offer is made to it.
        const watcher = { ...matchOffer, me: matchWatch.me } as State;

        expect(
            roomCues(
                { ...before, state: withScreen(matchWatch) },
                { ...after, state: withScreen(watcher) },
                'phone',
            ),
        ).toEqual([]);
    });

    /*
     * Whose turn it is (ADR 034 "Amended 2026-11-21 — whose turn it is"):
     * the seat whose turn begins hears that moment's cues and its own clock
     * on its phone, with a screen too, and feels one buzz; the seat that
     * just moved, a watcher and the screen hear nothing new, and a phone
     * that carries the room hears nothing twice.
     */
    const [whiteSide, blackSide] = playOf(matchPlay).sides;
    /** The match at `ply` with `turn` to move after `last`, seen by `viewer`; the room's screen `screen`. */
    const turnAt = (
        ply: number,
        turn: 'white' | 'black',
        last: Play['last'],
        viewer: string | null,
        screen: boolean,
    ): State =>
        withScreen(
            {
                ...matchPlay,
                me: { ...matchPlay.me, player_id: viewer },
                match: {
                    ...playOf(matchPlay),
                    ply,
                    turn,
                    last,
                    since_ms: starts + ply * 4_000,
                    flag_ms: starts + ply * 4_000 + 290_000,
                    offer: null,
                    legal: null,
                },
            } as State,
            screen,
        );
    const whiteMoves = { path: ['c3', 'd4'], captured: [], crowned: false };
    const blackTakes = {
        path: ['e5', 'c3', 'a1'],
        captured: ['d4', 'b2'],
        crowned: true,
    };
    const watcher = matchWatch.me.player_id ?? 'a-watcher';
    /** White moves (ply 2 → 3), then black takes and crowns (ply 3 → 4), each heard 50 ms after its moment began. */
    const twoMoves = (viewer: string | null, screen: boolean): RoomEar[] => [
        {
            state: turnAt(2, 'white', null, viewer, screen),
            now: starts + 8_100,
        },
        {
            state: turnAt(3, 'black', whiteMoves, viewer, screen),
            now: starts + 12_050,
        },
        {
            state: turnAt(4, 'white', blackTakes, viewer, screen),
            now: starts + 16_050,
        },
    ];
    const cuesOf = (ears: RoomEar[]) => ({
        whiteMoved: roomCues(ears[0], ears[1], 'phone'),
        blackTook: roomCues(ears[1], ears[2], 'phone'),
    });

    it("plays the other seat's move and one buzz on the phone of the seat whose turn begins, with a screen too", () => {
        // White's phone: its own move is silent; black's capture lands on its turn.
        expect(cuesOf(twoMoves(whiteSide.player_id, true))).toEqual({
            whiteMoved: [],
            blackTook: [
                { sound: 'capture', at: 320 },
                { sound: 'crown', at: 440 },
                { sound: 'buzz', at: 320 },
            ],
        });
        // Black's phone: white's move lands on its turn; its own capture is silent.
        expect(cuesOf(twoMoves(blackSide.player_id, true))).toEqual({
            whiteMoved: [
                { sound: 'move', at: 160 },
                { sound: 'buzz', at: 160 },
            ],
            blackTook: [],
        });
        // A watcher's phone hears neither, and the screen what it always heard.
        expect(cuesOf(twoMoves(watcher, true))).toEqual({
            whiteMoved: [],
            blackTook: [],
        });
        expect(
            heard(twoMoves(watcher, true), 'screen').map(({ cue }) => cue),
        ).toEqual([
            { sound: 'move', at: 160 },
            { sound: 'capture', at: 320 },
            { sound: 'crown', at: 440 },
        ]);
    });

    it('adds only the buzz on a phone that carries the room, so nothing is heard twice', () => {
        expect(cuesOf(twoMoves(whiteSide.player_id, false))).toEqual({
            whiteMoved: [{ sound: 'move', at: 160 }],
            blackTook: [
                { sound: 'capture', at: 320 },
                { sound: 'crown', at: 440 },
                { sound: 'buzz', at: 320 },
            ],
        });
        expect(cuesOf(twoMoves(blackSide.player_id, false))).toEqual({
            whiteMoved: [
                { sound: 'move', at: 160 },
                { sound: 'buzz', at: 160 },
            ],
            blackTook: [
                { sound: 'capture', at: 320 },
                { sound: 'crown', at: 440 },
            ],
        });
        expect(cuesOf(twoMoves(watcher, false))).toEqual({
            whiteMoved: [{ sound: 'move', at: 160 }],
            blackTook: [
                { sound: 'capture', at: 320 },
                { sound: 'crown', at: 440 },
            ],
        });
    });

    it("begins white's first turn with go and a buzz as the clock starts, on white's phone alone", () => {
        const start = (viewer: string | null, screen: boolean) =>
            roomCues(
                {
                    state: withScreen(
                        {
                            ...matchCountdown,
                            me: { ...matchCountdown.me, player_id: viewer },
                        } as State,
                        screen,
                    ),
                    now: starts - 1_500,
                },
                {
                    state: withScreen(
                        {
                            ...begun,
                            me: { ...begun.me, player_id: viewer },
                        } as State,
                        screen,
                    ),
                    now: starts + 80,
                },
                'phone',
            );

        expect(start(whiteSide.player_id, true)).toEqual([
            { sound: 'go', at: 0 },
            { sound: 'buzz', at: 0 },
        ]);
        expect(start(blackSide.player_id, true)).toEqual([]);
        expect(start(watcher, true)).toEqual([]);
        // Without a screen every phone hears go; white's alone feels it.
        expect(start(whiteSide.player_id, false)).toEqual([
            { sound: 'go', at: 0 },
            { sound: 'buzz', at: 0 },
        ]);
        expect(start(blackSide.player_id, false)).toEqual([
            { sound: 'go', at: 0 },
        ]);
    });

    it('runs the clock on the phone of the seat to move alone, ending at its flag, with a screen too', () => {
        const flagOf = (state: State) => playOf(state).flag_ms;
        const clockFor = (viewer: string | null) => {
            const state = turnAt(4, 'white', blackTakes, viewer, true);
            const flag = flagOf(state);

            return roomCues(
                { state, now: flag - 20_000 },
                { state, now: flag - CLOCK_MS - LEAD_MS + 100 },
                'phone',
            );
        };

        expect(clockFor(whiteSide.player_id)).toEqual([
            { sound: 'clock', at: CLOCK_MS + LEAD_MS - 100, ends: true },
        ]);
        expect(clockFor(blackSide.player_id)).toEqual([]);
        expect(clockFor(watcher)).toEqual([]);
    });

    it('plays nothing again for the same turn, a page opened on it or a turn heard long after', () => {
        const ears = twoMoves(blackSide.player_id, true);

        expect(
            roomCues(ears[1], { ...ears[1], now: starts + 12_400 }, 'phone'),
        ).toEqual([]);
        expect(roomCues(null, ears[1], 'phone')).toEqual([]);
        expect(
            roomCues(
                ears[0],
                { ...ears[1], now: starts + 12_000 + STALE_MS + 1 },
                'phone',
            ),
        ).toEqual([]);
    });

    it('fetches the seat files for a seat of a live match, not for a watcher or after the end', () => {
        expect(holdsSeat(matchCountdown)).toBe(true);
        expect(holdsSeat(matchPlay)).toBe(true);
        expect(holdsSeat(matchWatch)).toBe(false);
        expect(holdsSeat(matchOver)).toBe(false);
        expect(holdsSeat(answered)).toBe(false);
        expect(holdsSeat(null)).toBe(false);
    });

    it('closes the match, then opens its own final and cheers its winner', () => {
        const cues = roomCues(
            { state: matchPlay, now: starts + 15_000 },
            { state: matchOver, now: starts + 16_000 },
            'screen',
        );
        const standing = finalRows(matchOver);
        const timeline = finalTimeline(standingGroups(standing, []), 'screen');

        expect(cues.map((cue) => cue.sound)).toEqual([
            'close',
            'place',
            'roll',
            'fanfare',
            'cheer',
        ]);
        expect(cues.find((cue) => cue.sound === 'cheer')).toEqual({
            sound: 'cheer',
            at: (timeline.winnerAt ?? 0) + FANFARE_MS,
            players: [standing[0].player_id],
            many: false,
        });
    });

    /*
     * The computer's seat (ADR 036 decisions 11 and 15): the cheer names
     * only a player, so a final the computer wins keeps its fanfare and has
     * no name; and a side without a player is never a viewer's own seat.
     */
    const matchComputer = state('match.computer');

    /** The match against the computer, over: `outcome` with the player on white. */
    const computerOver = (outcome: 'white' | 'black' | 'draw'): State => {
        const played = matchComputer.match;

        if (played === null || matchComputer.game === null) {
            throw new Error('a match against the computer is needed');
        }

        const [player, computer] = played.sides;
        const places = { white: [1, 2], black: [2, 1], draw: [1, 1] }[outcome];
        const rows = [
            {
                player_id: player.player_id,
                points: { white: 3, black: 0, draw: 1 }[outcome],
                place: places[0],
            },
            { player_id: computer.player_id, points: 0, place: places[1] },
        ];

        return RoomState.parse({
            ...matchComputer,
            game: { ...matchComputer.game, phase: 'over' },
            setup: { ...matchComputer.setup, played: true },
            taunts: null,
            match: {
                ...played,
                phase: 'over',
                turn: null,
                flag_ms: null,
                legal: null,
                offer: null,
                can_offer: false,
                result: {
                    outcome,
                    ending: outcome === 'draw' ? 'agreed' : 'resigned',
                    // The winner first; both first in a draw.
                    standing: outcome === 'black' ? rows.reverse() : rows,
                    spent: [],
                },
            },
        });
    };
    const endOf = (ended: State) =>
        roomCues(
            { state: matchComputer, now: starts + 15_000 },
            { state: ended, now: starts + 16_000 },
            'phone',
        );
    const cheerOf = (cues: RoomCue[]) =>
        cues.find((cue) => cue.sound === 'cheer');

    it('cheers the player who beat the computer by name, as any winner', () => {
        const player = matchComputer.me.player_id;

        expect(cheerOf(endOf(computerOver('white')))).toMatchObject({
            sound: 'cheer',
            players: [player],
            many: false,
        });
    });

    it('keeps the fanfare and cheers nobody when the computer wins', () => {
        const cues = endOf(computerOver('black'));

        expect(cues.map((cue) => cue.sound)).toContain('fanfare');
        expect(cheerOf(cues)).toBeUndefined();
        // No name's clip is asked for: nothing the cheer holds is a seat without a player.
        expect(JSON.stringify(cues)).not.toContain('computer');
        expect(JSON.stringify(cues)).not.toContain('null');
    });

    it('cheers the one player of a draw with the computer, not two', () => {
        expect(cheerOf(endOf(computerOver('draw')))).toMatchObject({
            sound: 'cheer',
            players: [matchComputer.me.player_id],
            many: false,
        });
    });

    it("never takes the computer's seat for a viewer who does not play", () => {
        const hostOnly = {
            ...matchComputer,
            me: { player_id: null, host: true },
        } as State;

        // The player's phone fetches its seat's files; a host who only watches does not,
        // though the computer's side has no player and that viewer no row.
        expect(holdsSeat(matchComputer)).toBe(true);
        expect(holdsSeat(hostOnly)).toBe(false);
    });

    it('closes a match the host stopped without a final', () => {
        const over = matchOver.match;

        if (over?.phase !== 'over') {
            throw new Error('a match over is needed');
        }

        const stopped = {
            ...matchOver,
            match: {
                ...over,
                result: {
                    ...over.result,
                    outcome: 'none',
                    ending: 'stopped',
                    standing: over.result.standing.map((row) => ({
                        ...row,
                        points: 0,
                        place: null,
                    })),
                },
            },
        } as State;

        expect(
            roomCues(
                { state: matchPlay, now: starts + 15_000 },
                { state: stopped, now: starts + 16_000 },
                'screen',
            ),
        ).toEqual([{ sound: 'close', at: 0 }]);
    });
});
