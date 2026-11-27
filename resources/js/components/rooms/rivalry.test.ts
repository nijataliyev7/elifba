import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomPlayer, RoomRankedPlayer } from '@/rooms/contract';
import { COMPUTER_SEAT } from './computer';
import {
    contenders,
    interimPair,
    overtakeOf,
    pairBefore,
    rivalOf,
    rivalPair,
    splitOf,
    stripChange,
    stripMoment,
    stripRows,
} from './rivalry';
import type { RivalPair } from './rivalry';

/*
 * Rivals face to face (docs/architecture.md §12 "Rivals face to face (P2)",
 * ADR 027 decisions 3–5 and 15): the device takes two rows of the state's
 * ranking in the order the server gave them — the viewer against the
 * leader, or the runner-up when the viewer leads; the screen's first two;
 * a duel's sides in the order the players joined — and never sorts, sums
 * or judges. An overtake is a change of leader between two pairs the page
 * drew.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function player(id: string, present = true, host = false): RoomPlayer {
    return { id, name: id, present, host, voice: null, ready: false };
}

function row(
    id: string,
    points: number,
    place: number | null,
): RoomRankedPlayer {
    return { player_id: id, points, place };
}

/** The players in the order they joined, and the ranking as the server ordered it. */
function room(players: RoomPlayer[], ranking: RoomRankedPlayer[]) {
    return { players, ranking };
}

/** A pair's sides as `[left, right]` player ids. */
function sides(pair: RivalPair | null): [string | null, string | null] {
    return [pair?.left?.player_id ?? null, pair?.right?.player_id ?? null];
}

/** Eight players in a race, joined in this order. */
const EIGHT = [
    'aysel',
    'kamran',
    'murad',
    'resad',
    'leyla',
    'gunay',
    'elvin',
    'sebine',
].map((id) => player(id));

const RACE = room(EIGHT, [
    row('kamran', 57, 1),
    row('aysel', 46, 2),
    row('resad', 46, 2),
    row('murad', 41, 3),
    row('leyla', 24, 4),
    row('gunay', 21, 5),
    row('elvin', 12, 6),
    row('sebine', 0, null),
]);

describe('rivalPair', () => {
    it('puts the viewer against the leader, or the runner-up when the viewer leads', () => {
        // Behind: against the first place, with the gap to it.
        const murad = rivalPair(RACE, 'murad');
        expect(sides(murad)).toEqual(['murad', 'kamran']);
        expect(murad).toMatchObject({ mode: 'race', mine: true, gap: -16 });
        expect(murad?.leader).toBe('kamran');

        // Leading: against the next row of the server's order.
        const kamran = rivalPair(RACE, 'kamran');
        expect(sides(kamran)).toEqual(['kamran', 'aysel']);
        expect(kamran).toMatchObject({ gap: 11, leader: 'kamran' });

        // Last, without points: still against the leader, who holds a place.
        expect(sides(rivalPair(RACE, 'sebine'))).toEqual(['sebine', 'kamran']);

        // Exactly two players: the other one, whatever the places.
        const duel = room(
            [player('aysel'), player('kamran')],
            [row('kamran', 46, 1), row('aysel', 38, 2)],
        );
        expect(sides(rivalPair(duel, 'aysel'))).toEqual(['aysel', 'kamran']);
        expect(sides(rivalPair(duel, 'kamran'))).toEqual(['kamran', 'aysel']);
        expect(rivalPair(duel, 'aysel')).toMatchObject({
            mode: 'duel',
            mine: true,
            gap: -8,
        });
    });

    it("keeps a duel's sides in the order the players joined on the screen", () => {
        const players = [player('aysel'), player('kamran')];
        const before = rivalPair(
            room(players, [row('aysel', 46, 1), row('kamran', 38, 2)]),
            null,
        );
        const after = rivalPair(
            room(players, [row('kamran', 57, 1), row('aysel', 46, 2)]),
            null,
        );

        // The meeting point moves both ways; the sides stay put.
        expect(sides(before)).toEqual(['aysel', 'kamran']);
        expect(sides(after)).toEqual(['aysel', 'kamran']);
        expect(before).toMatchObject({ mine: false, gap: 8, leader: 'aysel' });
        expect(after).toMatchObject({ gap: -11, leader: 'kamran' });

        // A host who does not play sees the same pair as the screen.
        const host = room(
            [player('resad', false, true), ...players],
            [row('kamran', 57, 1), row('aysel', 46, 2), row('resad', 0, null)],
        );
        expect(sides(rivalPair(host, null))).toEqual(['aysel', 'kamran']);
    });

    it('puts the first place left and the second right on the screen', () => {
        const screen = rivalPair(RACE, null);

        expect(sides(screen)).toEqual(['kamran', 'aysel']);
        expect(screen).toMatchObject({ mode: 'race', mine: false, gap: 11 });

        // An overtake swaps them.
        const after = rivalPair(
            room(EIGHT, [
                row('aysel', 60, 1),
                row('kamran', 57, 2),
                ...RACE.ranking.slice(2),
            ]),
            null,
        );
        expect(sides(after)).toEqual(['aysel', 'kamran']);

        // A viewer who is not in the race sees the screen's pair too.
        expect(sides(rivalPair(RACE, '01kaqrx9zz9zz9zz9zz9zz9zzz'))).toEqual([
            'kamran',
            'aysel',
        ]);
    });

    it('keeps a player who left while they hold a place, and drops one without points and a host who does not play', () => {
        const players = [
            player('resad', false, true),
            player('aysel'),
            player('kamran', false),
            player('tural', false),
            player('murad'),
        ];
        const ranking = [
            row('kamran', 30, 1),
            row('aysel', 20, 2),
            row('murad', 0, null),
            row('resad', 0, null),
            row('tural', 0, null),
        ];

        expect(
            contenders(ranking, players).map((one) => one.player_id),
        ).toEqual(['kamran', 'aysel', 'murad']);

        const pair = rivalPair(room(players, ranking), 'aysel');
        expect(sides(pair)).toEqual(['aysel', 'kamran']);
        expect(pair?.mode).toBe('race');

        // One who left without points turns a race back into a duel …
        const two = rivalPair(
            room(players, [
                row('aysel', 20, 1),
                row('murad', 0, null),
                row('resad', 0, null),
                row('tural', 0, null),
            ]),
            'murad',
        );
        expect(two?.mode).toBe('duel');
        expect(sides(two)).toEqual(['murad', 'aysel']);

        // … and alone in the room nobody has a rival.
        expect(
            rivalPair(
                room(
                    [player('resad', false, true), player('aysel')],
                    [row('aysel', 20, 1), row('resad', 0, null)],
                ),
                'aysel',
            ),
        ).toBeNull();
    });

    it('has no rival before anyone holds a place', () => {
        const players = [player('aysel'), player('kamran'), player('murad')];
        const zero = room(players, [
            row('aysel', 0, null),
            row('kamran', 0, null),
            row('murad', 0, null),
        ]);

        // A race at 0: «Siz 0» against an empty side on a phone, two empty sides on the screen.
        expect(rivalPair(zero, 'kamran')).toMatchObject({
            mode: 'race',
            mine: true,
            right: null,
            gap: null,
            leader: null,
            split: null,
        });
        expect(sides(rivalPair(zero, 'kamran'))).toEqual(['kamran', null]);
        expect(sides(rivalPair(zero, null))).toEqual([null, null]);
        expect(rivalPair(zero, null)?.gap).toBeNull();

        // A duel at 0 : 0 stands level, split in the middle.
        const duel = room(
            [player('aysel'), player('kamran')],
            [row('aysel', 0, null), row('kamran', 0, null)],
        );
        expect(rivalPair(duel, 'kamran')).toMatchObject({
            mode: 'duel',
            gap: 0,
            leader: null,
            split: 50,
        });

        // One place held: the leader has no rival with a place yet, and leads by all their points.
        const first = room(players, [
            row('murad', 15, 1),
            row('aysel', 0, null),
            row('kamran', 0, null),
        ]);
        expect(sides(rivalPair(first, 'murad'))).toEqual(['murad', null]);
        expect(rivalPair(first, 'murad')).toMatchObject({
            gap: 15,
            leader: 'murad',
            split: null,
        });
        expect(sides(rivalPair(first, null))).toEqual(['murad', null]);
        expect(sides(rivalPair(first, 'aysel'))).toEqual(['aysel', 'murad']);
        expect(rivalPair(first, 'aysel')?.gap).toBe(-15);
    });

    it('shows a shared place as one side and a count', () => {
        // The viewer shares the first place with two others: level, and «+1 nəfər».
        const tie = room(EIGHT.slice(0, 4), [
            row('aysel', 30, 1),
            row('kamran', 30, 1),
            row('murad', 30, 1),
            row('resad', 12, 2),
        ]);

        expect(sides(rivalPair(tie, 'kamran'))).toEqual(['kamran', 'aysel']);
        expect(rivalPair(tie, 'kamran')).toMatchObject({
            gap: 0,
            leader: null,
            more: 1,
            split: 50,
        });
        expect(sides(rivalPair(tie, 'aysel'))).toEqual(['aysel', 'kamran']);
        expect(rivalPair(tie, 'aysel')?.more).toBe(1);
        expect(rivalPair(tie, null)).toMatchObject({ gap: 0, more: 1 });
        // Behind a shared first place: the first row of it, and the others counted.
        expect(sides(rivalPair(tie, 'resad'))).toEqual(['resad', 'aysel']);
        expect(rivalPair(tie, 'resad')?.more).toBe(2);

        // The second place shared on the screen's right side.
        expect(rivalPair(RACE, null)?.more).toBe(1);
        // Nobody shares the leader's place: no count.
        expect(rivalPair(RACE, 'murad')?.more).toBe(0);
    });

    it("never reorders the server's rows", () => {
        // An order no sort by points would give: the server's word is final.
        const odd = room(EIGHT.slice(0, 3), [
            row('murad', 10, 1),
            row('aysel', 25, 2),
            row('kamran', 5, 3),
        ]);
        const copy = structuredClone(odd);

        expect(sides(rivalPair(odd, null))).toEqual(['murad', 'aysel']);
        expect(sides(rivalPair(odd, 'kamran'))).toEqual(['kamran', 'murad']);
        expect(rivalPair(odd, null)?.gap).toBe(-15);
        expect(odd).toEqual(copy);

        // And the fixtures of every phase give a pair or none, without a sort.
        for (const [name, data] of Object.entries(fixtures)) {
            const state = RoomState.parse(data);
            const pair = rivalPair(state, state.me.player_id);
            const order = state.ranking.map((one) => one.player_id);

            if (
                pair?.left &&
                pair.right &&
                pair.mode === 'race' &&
                !pair.mine
            ) {
                expect(order.indexOf(pair.left.player_id), name).toBeLessThan(
                    order.indexOf(pair.right.player_id),
                );
            }
        }
    });

    it('splits the bar by the two totals, never letting a side vanish', () => {
        expect(splitOf(0, 0)).toBe(50);
        expect(splitOf(30, 10)).toBe(75);
        expect(splitOf(10, 0)).toBe(88);
        expect(splitOf(0, 10)).toBe(12);
        expect(splitOf(1, 99)).toBe(12);
        expect(rivalPair(RACE, null)?.split).toBeCloseTo((100 * 57) / 103);
    });
});

describe('overtakeOf', () => {
    const players = [player('aysel'), player('kamran')];
    const pair = (aysel: number, kamran: number, viewer: string | null) =>
        rivalPair(
            room(
                players,
                aysel >= kamran
                    ? [
                          row('aysel', aysel, aysel > 0 ? 1 : null),
                          row(
                              'kamran',
                              kamran,
                              kamran > 0 ? (kamran === aysel ? 1 : 2) : null,
                          ),
                      ]
                    : [row('kamran', kamran, 1), row('aysel', aysel, 2)],
            ),
            viewer,
        );

    it('finds an overtake only between two pairs the page drew, and none for drawing level', () => {
        // The lead changes hands: the one who took it.
        expect(overtakeOf(pair(46, 38, null), pair(46, 57, null))).toBe(
            'kamran',
        );
        expect(overtakeOf(pair(46, 38, 'aysel'), pair(46, 57, 'aysel'))).toBe(
            'kamran',
        );
        // Leading from level is one; becoming level is none; keeping the lead is none.
        expect(overtakeOf(pair(0, 0, null), pair(15, 0, null))).toBe('aysel');
        expect(overtakeOf(pair(20, 10, null), pair(25, 25, null))).toBeNull();
        expect(overtakeOf(pair(20, 10, null), pair(30, 25, null))).toBeNull();
        // A page that drew no pair before (opened on the reveal) shows none.
        expect(overtakeOf(null, pair(46, 57, null))).toBeNull();

        // A race: a pair without a rival before is none; the phone's rival changing as the viewer leads is one.
        const three = [...players, player('murad')];
        const blank = rivalPair(
            room(three, [
                row('aysel', 0, null),
                row('kamran', 0, null),
                row('murad', 0, null),
            ]),
            null,
        );
        const after = rivalPair(
            room(three, [
                row('murad', 15, 1),
                row('aysel', 10, 2),
                row('kamran', 0, null),
            ]),
            null,
        );
        expect(overtakeOf(blank, after)).toBeNull();

        const behind = rivalPair(
            room(three, [
                row('murad', 15, 1),
                row('aysel', 10, 2),
                row('kamran', 0, null),
            ]),
            'aysel',
        );
        const ahead = rivalPair(
            room(three, [
                row('aysel', 27, 1),
                row('murad', 15, 2),
                row('kamran', 0, null),
            ]),
            'aysel',
        );
        expect(sides(behind)).toEqual(['aysel', 'murad']);
        expect(sides(ahead)).toEqual(['aysel', 'murad']);
        expect(overtakeOf(behind, ahead)).toBe('aysel');

        // Further down, whose leader changed: the new leader took the lead.
        const kamranBefore = rivalPair(
            room(three, [
                row('murad', 15, 1),
                row('aysel', 10, 2),
                row('kamran', 5, 3),
            ]),
            'kamran',
        );
        const kamranAfter = rivalPair(
            room(three, [
                row('aysel', 27, 1),
                row('murad', 15, 2),
                row('kamran', 5, 3),
            ]),
            'kamran',
        );
        expect(overtakeOf(kamranBefore, kamranAfter)).toBe('aysel');
    });
});

describe('interimPair', () => {
    it("moves the sides the strip showed first, with their new totals, when the pair's members or sides changed", () => {
        const before = rivalPair(RACE, null);
        const ranking = [
            row('aysel', 60, 1),
            row('kamran', 57, 2),
            ...RACE.ranking.slice(2),
        ];
        const after = rivalPair(room(EIGHT, ranking), null);
        const interim = interimPair(before, after, ranking);

        expect(sides(interim)).toEqual(['kamran', 'aysel']);
        expect(interim?.left).toEqual(row('kamran', 57, 1));
        expect(interim?.right).toEqual(row('aysel', 60, 2));
        expect(interim).toMatchObject({ gap: -3, leader: 'aysel' });

        // The same sides: nothing to move first.
        expect(interimPair(before, before, RACE.ranking)).toBeNull();
        // Without a pair drawn before, or a side: nothing either.
        expect(interimPair(null, after, ranking)).toBeNull();
    });

    it('starts from the totals before the round', () => {
        const pair = rivalPair(RACE, 'murad');

        if (pair === null) {
            throw new Error('a pair is needed');
        }

        const before = pairBefore(pair, [
            { player_id: 'murad', correct: true, points: 17 },
            { player_id: 'kamran', correct: true, points: 19 },
        ]);

        expect(before.left?.points).toBe(24);
        expect(before.right?.points).toBe(38);
        expect(before.gap).toBe(-14);
        expect(pairBefore(pair, null)).toBe(pair);

        // A taunt counted at this close is added back: the movement starts from before the cost (ADR 029).
        const paid = pairBefore(
            pair,
            [
                { player_id: 'murad', correct: true, points: 17 },
                { player_id: 'kamran', correct: true, points: 19 },
            ],
            [{ player_id: 'murad', count: 2 }],
        );

        expect(paid.left?.points).toBe(26);
        expect(paid.right?.points).toBe(38);
        expect(paid.gap).toBe(-12);
    });
});

/*
 * A match's strip (ADR 034 decisions 23 and 24): always its two seats, the
 * totals their pieces left; a seat's phone «Siz» against the other seat,
 * a watcher and the screen white against black; 👑 by the side with more
 * pieces and by neither when level; a capture's count starts from the
 * pieces before it; a change of the lead at a move is an overtake.
 */
describe('a match on the strip', () => {
    type Match = NonNullable<RoomState['match']>;

    const play = RoomState.parse(
        fixtures['../../../../tests/Fixtures/rooms/state.match.play.json'],
    );
    const watch = RoomState.parse(
        fixtures['../../../../tests/Fixtures/rooms/state.match.watch.json'],
    );
    const match = play.match as Match;
    const [white, black] = match.sides;
    /** The same match with these pieces left and this last move. */
    const pieces = (
        source: RoomState,
        counts: [number, number],
        last: Match['last'] = match.last,
        ply = match.ply,
    ): RoomState =>
        ({
            ...source,
            match: {
                ...(source.match as Match),
                ply,
                last,
                sides: [
                    { ...white, pieces: counts[0] },
                    { ...black, pieces: counts[1] },
                ],
            },
        }) as RoomState;

    it('takes the two seats with their pieces, the side with more first-placed', () => {
        expect(stripRows(play)).toEqual({
            rows: [
                { player_id: white.player_id, points: 12, place: null },
                { player_id: black.player_id, points: 12, place: null },
            ],
            seats: true,
        });
        expect(
            stripRows(pieces(play, [9, 11])).rows.map((row) => row.place),
        ).toEqual([2, 1]);
        // A race's rows are its ranking.
        expect(stripRows({ ranking: play.ranking, match: null }).rows).toBe(
            play.ranking,
        );
    });

    it("puts a seat on the left of its own phone, white on a watcher's and the screen's", () => {
        const mine = rivalPair(pieces(play, [11, 12]), play.me.player_id);

        expect(mine).toMatchObject({
            mode: 'duel',
            unit: 'pieces',
            mine: true,
            gap: -1,
        });
        expect(mine?.left?.player_id).toBe(white.player_id);
        expect(mine?.leader).toBe(black.player_id);

        // Black's own phone: black on the left, the board turned round elsewhere.
        expect(rivalPair(play, black.player_id)?.left?.player_id).toBe(
            black.player_id,
        );

        for (const viewer of [watch.me.player_id, null]) {
            const pair = rivalPair(watch, viewer);

            expect(pair?.mine).toBe(false);
            expect([pair?.left?.player_id, pair?.right?.player_id]).toEqual([
                white.player_id,
                black.player_id,
            ]);
            expect(pair?.leader).toBeNull();
        }
    });

    it('counts a capture from the pieces before it, and nothing for a quiet move', () => {
        const capture = pieces(
            play,
            [12, 10],
            {
                path: ['d4', 'f6', 'd8'],
                captured: ['e5', 'e7'],
                crowned: false,
            },
            3,
        );
        // The piece that moved is white's (it stands on the path's end).
        const moved = {
            ...capture,
            match: {
                ...(capture.match as Match),
                position: [
                    ...(capture.match as Match).position.filter(
                        (piece) => piece.square !== 'd8',
                    ),
                    { square: 'd8', color: 'white', king: true },
                ],
            },
        } as RoomState;
        const change = stripChange(moved);

        expect(change.results).toEqual([
            { player_id: black.player_id, points: -2 },
        ]);

        const pair = rivalPair(moved, null);

        expect(pair).not.toBeNull();
        expect(
            pairBefore(pair as RivalPair, change.results, change.spent),
        ).toMatchObject({
            left: { points: 12 },
            right: { points: 12 },
            gap: 0,
        });
        expect(stripChange(play).results).toBeNull();
    });

    it('is an overtake when the lead changes at a move, each ply its own moment', () => {
        const level = rivalPair(play, null);
        const ahead = rivalPair(pieces(play, [12, 11], match.last, 3), null);
        const behind = rivalPair(pieces(play, [10, 11], match.last, 4), null);

        expect(overtakeOf(level, ahead)).toBe(white.player_id);
        expect(overtakeOf(ahead, behind)).toBe(black.player_id);
        expect(overtakeOf(behind, behind)).toBeNull();
        expect(stripMoment(play)).toEqual({
            key: `${play.game?.id}|ply:2`,
            moves: true,
        });
        expect(stripMoment(pieces(play, [12, 12], match.last, 3)).key).not.toBe(
            stripMoment(play).key,
        );
    });
});

/*
 * The computer's seat on the strip (ADR 036 decisions 11, 13 and 15): the
 * side without a player is a row of its own key — a side like any other for
 * the pair, its lead and an overtake — never the viewer's own, and never a
 * rival to throw at.
 */
describe("the computer's seat on the strip", () => {
    type Match = NonNullable<RoomState['match']>;

    const computer = RoomState.parse(
        fixtures['../../../../tests/Fixtures/rooms/state.match.computer.json'],
    );
    const match = computer.match as Match;
    const [white, black] = match.sides;
    const player = computer.me.player_id;
    /** The same match with these pieces left, white's then the computer's. */
    const pieces = (
        counts: [number, number],
        last: Match['last'] = match.last,
        ply = match.ply,
    ): RoomState =>
        ({
            ...computer,
            match: {
                ...match,
                ply,
                last,
                sides: [
                    { ...white, pieces: counts[0] },
                    { ...black, pieces: counts[1] },
                ],
            },
        }) as RoomState;

    it('takes the player and the computer as its two rows, the computer under its own key', () => {
        expect([white.player_id, black.player_id]).toEqual([player, null]);
        expect(stripRows(computer)).toEqual({
            rows: [
                { player_id: player, points: 12, place: null },
                { player_id: COMPUTER_SEAT, points: 12, place: null },
            ],
            seats: true,
        });
    });

    it("puts the player on the left of their phone and white on the left elsewhere, the computer never anyone's own", () => {
        const mine = rivalPair(pieces([10, 12]), player);

        expect(mine).toMatchObject({
            mode: 'duel',
            unit: 'pieces',
            mine: true,
        });
        expect(sides(mine)).toEqual([player, COMPUTER_SEAT]);
        expect([mine?.gap, mine?.leader]).toEqual([-2, COMPUTER_SEAT]);

        // The screen and a host who only watches have no viewer: the computer's side is not theirs.
        const shown = rivalPair(pieces([10, 12]), null);

        expect(shown?.mine).toBe(false);
        expect(sides(shown)).toEqual([player, COMPUTER_SEAT]);
        expect(shown?.leader).toBe(COMPUTER_SEAT);
    });

    it('offers no rival to throw at: nobody sits there', () => {
        const pair = rivalPair(computer, player);

        expect(pair?.right?.player_id).toBe(COMPUTER_SEAT);
        expect(rivalOf(pair, computer.players)).toBeNull();
        // Between two players the other seat is the rival, as ever.
        const play = RoomState.parse(
            fixtures['../../../../tests/Fixtures/rooms/state.match.play.json'],
        );

        expect(
            rivalOf(rivalPair(play, play.me.player_id), play.players)?.id,
        ).toBe((play.match as Match).sides[1].player_id);
    });

    it("counts the computer's capture and its overtake as a side's", () => {
        // The computer (black) took one of white's: e5 over d4 to c3.
        const taken = pieces(
            [11, 12],
            { path: ['e5', 'c3'], captured: ['d4'], crowned: false },
            3,
        );
        const moved = {
            ...taken,
            match: {
                ...(taken.match as Match),
                position: [
                    ...match.position.filter(
                        (piece) => !['d4', 'c3', 'e5'].includes(piece.square),
                    ),
                    { square: 'c3', color: 'black' as const, king: false },
                ],
            },
        } as RoomState;

        expect(stripChange(moved).results).toEqual([
            { player_id: player, points: -1 },
        ]);

        // And the player's capture is taken from the computer's row.
        const won = {
            ...moved,
            match: {
                ...(moved.match as Match),
                position: [
                    ...match.position.filter(
                        (piece) => !['d4', 'f6', 'e5'].includes(piece.square),
                    ),
                    { square: 'f6', color: 'white' as const, king: false },
                ],
                last: { path: ['d4', 'f6'], captured: ['e5'], crowned: false },
            },
        } as RoomState;

        expect(stripChange(won).results).toEqual([
            { player_id: COMPUTER_SEAT, points: -1 },
        ]);

        const level = rivalPair(pieces([12, 12]), player);
        const behind = rivalPair(taken, player);

        expect(overtakeOf(level, behind)).toBe(COMPUTER_SEAT);
        expect(
            pairBefore(behind as RivalPair, stripChange(moved).results),
        ).toMatchObject({ gap: 0, leader: null });
    });
});

describe('a domino game’s strip (ADR 038 decision 48)', () => {
    const RESAD = '01kaqrx2ab3c4d5e6f7g8h9jkm';
    const AYGUN = '01kaqrx3mn4p5q6r7s8t9vwxyz';
    const TURAL = '01kaqrx4bc5d6e7f8g9h0jkmnp';
    const LEYLA = '01kaqrx5cd6e7f8g9h0jkmnpqr';
    const KAMAL = '01kaqrx6de7f8g9h0jkmnpqrst';

    function domino(name: string) {
        return RoomState.parse(
            fixtures[
                `../../../../tests/Fixtures/rooms/state.domino.${name}.json`
            ],
        );
    }

    /** A table of four, each for itself, from a fixture of pairs: the balances and places given, in the server's order. */
    function four(
        order: [number, number, number][],
        seatOf = (side: number) => side,
    ) {
        const base = domino('play');
        const game = base.domino;

        if (game === null) {
            throw new Error('play has a domino');
        }

        return {
            ...base,
            domino: {
                ...game,
                seats: game.seats.map((seat) => ({ ...seat, side: seat.seat })),
                sides: order.map(([side, balance, place]) => ({
                    side,
                    seats: [seatOf(side)],
                    balance,
                    open: balance > 0,
                    waiting: 0,
                    tries: 0,
                    place,
                })),
            },
        };
    }

    it('draws every fixture’s sides in the server’s order, never sorted, their totals the balances', () => {
        const names = Object.keys(fixtures).filter((key) =>
            key.includes('state.domino.'),
        );

        expect(names).toHaveLength(14);

        for (const name of names) {
            const state = RoomState.parse(fixtures[name]);
            const { rows, seats } = stripRows(state);

            if (state.domino === null) {
                expect(seats, name).toBe(false);

                continue;
            }

            expect(seats, name).toBe(true);
            expect(
                rows.map((row) => row.points),
                name,
            ).toEqual(state.domino.sides.map((side) => side.balance));
        }

        // A pair is its own row; 👑 by the higher balance, by neither when level.
        expect(stripRows(domino('sheet.finished')).rows).toEqual([
            { player_id: 'pair:1', points: 16, place: 1 },
            { player_id: 'pair:0', points: 0, place: 2 },
        ]);
        expect(stripRows(domino('play')).rows.map((row) => row.place)).toEqual([
            null,
            null,
        ]);
        expect(
            stripRows(domino('away')).rows.map((row) => row.player_id),
        ).toEqual([RESAD, AYGUN]);
    });

    it('gives every fixture a pair of its sides, the viewer’s own on the left where it sits', () => {
        for (const name of Object.keys(fixtures).filter((key) =>
            key.includes('state.domino.'),
        )) {
            const state = RoomState.parse(fixtures[name]);

            if (state.domino === null) {
                continue;
            }

            const screen = name.endsWith('state.domino.screen.json');
            const viewer = screen ? null : state.me.player_id;
            const pair = rivalPair(state, viewer);
            const keys = stripRows(state).rows.map((row) => row.player_id);
            const seated = state.domino.seats.some(
                (seat) => seat.player_id === viewer,
            );

            expect(pair, name).not.toBeNull();
            expect(keys, name).toContain(pair?.left?.player_id);
            expect(keys, name).toContain(pair?.right?.player_id);
            expect(pair?.mine, name).toBe(seated);
            expect(pair?.unit, name).toBe('points');
        }
    });

    it('shows two sides face to face: the viewer’s own on the left, elsewhere the side of seat 0', () => {
        const play = rivalPair(domino('play'), RESAD);

        expect(play).toMatchObject({
            mode: 'duel',
            unit: 'points',
            mine: true,
            left: { player_id: 'pair:0' },
            right: { player_id: 'pair:1' },
            gap: 0,
            leader: null,
            split: 50,
        });

        // The server orders the leading pair first; the viewer of the other pair stays on the left.
        const finished = domino('sheet.finished');

        expect(rivalPair(finished, AYGUN)).toMatchObject({
            mine: true,
            left: { player_id: 'pair:1', points: 16 },
            right: { player_id: 'pair:0', points: 0 },
            gap: 16,
            leader: 'pair:1',
        });
        expect(rivalPair(finished, RESAD)).toMatchObject({
            left: { player_id: 'pair:0' },
            gap: -16,
            leader: 'pair:1',
        });

        // A watcher and the screen: seat 0's side on the left whatever the order.
        for (const viewer of [KAMAL, null]) {
            expect(rivalPair(finished, viewer)).toMatchObject({
                mine: false,
                left: { player_id: 'pair:0' },
                right: { player_id: 'pair:1' },
            });
        }

        // At two, each side is its player.
        expect(rivalPair(domino('away'), AYGUN)).toMatchObject({
            mine: true,
            left: { player_id: AYGUN },
            right: { player_id: RESAD },
        });
    });

    it('shows the viewer against the leader at three and four seats, or the runner-up when leading; elsewhere the first two', () => {
        // Seat 2 leads; seats 0 and 3 share the second place; seat 1 last.
        const state = four([
            [2, 40, 1],
            [0, 20, 2],
            [3, 20, 2],
            [1, 0, 3],
        ]);

        expect(rivalPair(state, AYGUN)).toMatchObject({
            mode: 'race',
            mine: true,
            left: { player_id: AYGUN, points: 0 },
            right: { player_id: TURAL, points: 40 },
            leader: TURAL,
        });
        expect(rivalPair(state, TURAL)).toMatchObject({
            left: { player_id: TURAL },
            right: { player_id: RESAD },
            // Leyla shares the runner-up's place.
            more: 1,
        });
        expect(rivalPair(state, null)).toMatchObject({
            mine: false,
            left: { player_id: TURAL },
            right: { player_id: RESAD },
        });
        expect(rivalPair(state, KAMAL)?.left?.player_id).toBe(TURAL);

        // Level at the start: every side stands, none crowned.
        const level = four([
            [0, 0, 1],
            [1, 0, 1],
            [2, 0, 1],
            [3, 0, 1],
        ]);

        expect(rivalPair(level, LEYLA)).toMatchObject({
            left: { player_id: LEYLA, place: null },
            right: { player_id: RESAD, place: null },
            more: 0,
        });
    });

    it('keys each computer’s side by its seat, and throws at nobody across from it', () => {
        const state = four([
            [0, 20, 1],
            [1, 0, 2],
            [2, 0, 2],
            [3, 0, 2],
        ]);
        const game = state.domino;
        const computers = {
            ...state,
            domino: {
                ...game,
                seats: game.seats.map((seat) =>
                    seat.seat === 0
                        ? seat
                        : {
                              ...seat,
                              player_id: null,
                              standing: 'computer' as const,
                          },
                ),
            },
        };
        const pair = rivalPair(computers, RESAD);

        expect(stripRows(computers).rows.map((row) => row.player_id)).toEqual([
            RESAD,
            'computer:1',
            'computer:2',
            'computer:3',
        ]);
        expect(pair?.right?.player_id).toBe('computer:1');
        expect(rivalOf(pair, computers.players, computers.domino)).toBeNull();
    });

    it('throws at the pair across through its first present player, in the order of its seats', () => {
        const state = domino('play');
        const pair = rivalPair(state, RESAD);

        expect(rivalOf(pair, state.players, state.domino)).toEqual({
            id: AYGUN,
            name: 'Aygün Məmmədova',
        });

        const away = state.players.map((player) =>
            player.id === AYGUN ? { ...player, present: false } : player,
        );

        expect(rivalOf(pair, away, state.domino)?.id).toBe(LEYLA);
        expect(
            rivalOf(rivalPair(state, KAMAL), state.players, state.domino),
        ).toBeNull();
        expect(
            rivalOf(rivalPair(domino('away'), AYGUN), state.players)?.id,
        ).toBe(RESAD);
    });

    it('moves at a hand’s end: its moment is the hand, its count what the sheet wrote, an overtake a new leader', () => {
        const play = domino('play');
        const finished = domino('sheet.finished');

        expect(stripMoment(play)).toEqual({
            key: '01kaqs0a1b2c3d4e5f6g7h8j9k|hand:1|open',
            moves: false,
        });
        expect(stripMoment(finished)).toEqual({
            key: '01kaqs0a1b2c3d4e5f6g7h8j9k|hand:1|end',
            moves: true,
        });
        expect(stripMoment(domino('countdown')).key).toBe(
            '01kaqs0a1b2c3d4e5f6g7h8j9k|hand:-|open',
        );

        expect(stripChange(play)).toEqual({ results: null, spent: [] });
        expect(stripChange(finished)).toEqual({
            results: [
                { player_id: 'pair:1', points: 16 },
                { player_id: 'pair:0', points: 0 },
            ],
            spent: [],
        });

        // From level to a lead is an overtake; the same leader again is none.
        const before = rivalPair(play, AYGUN);
        const after = rivalPair(finished, AYGUN);

        expect(overtakeOf(before, after)).toBe('pair:1');
        expect(overtakeOf(after, rivalPair(finished, AYGUN))).toBeNull();
        // The count starts from before the hand.
        expect(
            after === null
                ? null
                : pairBefore(after, stripChange(finished).results),
        ).toMatchObject({ gap: 0, leader: null });
    });
});
