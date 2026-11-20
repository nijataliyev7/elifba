import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomPlayer, RoomRankedPlayer } from '@/rooms/contract';
import {
    contenders,
    interimPair,
    overtakeOf,
    pairBefore,
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
    return { id, name: id, present, host, voice: null };
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
