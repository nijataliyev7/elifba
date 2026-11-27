import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomRankedPlayer } from '@/rooms/contract';
import { COMPUTER_SEAT } from './computer';
import {
    finalRows,
    finalSpent,
    finalTier,
    finalTimeline,
    holdsFirst,
} from './final';
import dominoOver from '../../../../tests/Fixtures/rooms/state.domino.over.json';
import dominoStopped from '../../../../tests/Fixtures/rooms/state.domino.stopped.json';
import over from '../../../../tests/Fixtures/rooms/state.match.over.json';
import raceOver from '../../../../tests/Fixtures/rooms/state.over.json';
import { standingGroups } from './standing-groups';

/*
 * The final of a room game (docs/frontend.md rule 15 "The final", ADR
 * 025): the places open from the last to the first, a place that players
 * share opens at once, the first three slower, the first last.
 */
const LABELS = Array.from({ length: 20 }, (_, index) => `${index + 1}.`);

function ranking(places: (number | null)[]): RoomRankedPlayer[] {
    return places.map((place, index) => ({
        player_id: `p${index}`,
        points: place === null ? 0 : 100 - place,
        place,
    }));
}

describe('finalTimeline', () => {
    it('opens the places from the last to the first, players without points first', () => {
        const groups = standingGroups(ranking([1, 2, 3, 4, 5, null]), LABELS);
        const { opensAt, winnerAt, endsAt } = finalTimeline(groups, 'screen');

        // Shown first place first; each opens after the one below it.
        for (let index = 0; index + 1 < opensAt.length; index++) {
            expect(opensAt[index]).toBeGreaterThan(opensAt[index + 1]);
        }

        expect(opensAt.at(-1)).toBe(Math.min(...opensAt));
        expect(winnerAt).toBe(opensAt[0]);
        expect(endsAt).toBe(opensAt[0]);
    });

    it('opens a shared place at once, with all who share it', () => {
        const groups = standingGroups(
            ranking([1, 1, 2, 3, 3, 3, null]),
            LABELS,
        );
        const { opensAt } = finalTimeline(groups, 'screen');

        expect(groups.map((group) => group.rows.length)).toEqual([2, 1, 3, 1]);
        expect(opensAt).toHaveLength(4);
        expect(new Set(opensAt).size).toBe(4);
    });

    it('slows down for the first three and keeps the first for last', () => {
        const groups = standingGroups(ranking([1, 2, 3, 4, 5, 6]), LABELS);
        const { opensAt } = finalTimeline(groups, 'screen');
        const [first, second, third, fourth, fifth] = opensAt;

        const restStep = fourth - fifth;
        const beforeThird = third - fourth;
        const beforeSecond = second - third;
        const beforeFirst = first - second;

        expect(beforeThird).toBeGreaterThan(restStep);
        expect(beforeSecond).toBeGreaterThan(beforeThird);
        expect(beforeFirst).toBeGreaterThan(beforeSecond);
    });

    it('keeps the rest quick however many play', () => {
        const places = Array.from({ length: 20 }, (_, index) => index + 1);
        const groups = standingGroups(ranking(places), LABELS);
        const { opensAt } = finalTimeline(groups, 'screen');

        // Places 4 to 20 together within the budget.
        expect(opensAt[3] - opensAt[19]).toBeLessThanOrEqual(1600);
        expect(finalTimeline(groups, 'phone').endsAt).toBeLessThan(
            finalTimeline(groups, 'screen').endsAt,
        );
    });

    it('has no winner when nobody scored', () => {
        const groups = standingGroups(ranking([null, null, null]), LABELS);
        const timeline = finalTimeline(groups, 'phone');

        expect(groups).toHaveLength(1);
        expect(timeline.winnerAt).toBeNull();
        expect(timeline.opensAt).toEqual([timeline.endsAt]);
    });

    it('opens nothing for an empty ranking', () => {
        expect(finalTimeline([], 'screen')).toEqual({
            opensAt: [],
            winnerAt: null,
            endsAt: 0,
        });
    });
});

describe('finalTier', () => {
    it('emphasises the first three places only', () => {
        expect([1, 2, 3, 4, null].map(finalTier)).toEqual([
            'first',
            'second',
            'third',
            null,
            null,
        ]);
    });
});

describe('holdsFirst', () => {
    it('finds the viewer in the first place, alone or shared', () => {
        const rows = ranking([1, 1, 2]);

        expect(holdsFirst(rows, 'p0')).toBe(true);
        expect(holdsFirst(rows, 'p1')).toBe(true);
        expect(holdsFirst(rows, 'p2')).toBe(false);
        expect(holdsFirst(rows, null)).toBe(false);
        expect(holdsFirst(rows, 'nobody')).toBe(false);
    });
});

/*
 * A match's final (ADR 034 decision 25): its own two places — the winner
 * first, both first in a draw, by the outcome — never the room's standing,
 * which stands under it; a match stopped without a result opens none. A
 * race's final is the state's ranking, as it was.
 */
describe('finalRows', () => {
    const match = RoomState.parse(over);

    it("opens a match's own places, and a race's ranking", () => {
        const result =
            match.match?.phase === 'over' ? match.match.result : null;

        expect(finalRows(match)).toEqual(result?.standing);
        expect(finalRows(match)).not.toEqual(match.ranking);
        expect(finalRows(match).map((row) => row.place)).toEqual([1, 2]);

        const race = RoomState.parse(raceOver);

        expect(finalRows(race)).toBe(race.ranking);
    });

    it('opens both first after a draw, and nothing after a stop', () => {
        if (match.match?.phase !== 'over') {
            throw new Error('a match over is needed');
        }

        const ended = match.match;
        const draw = {
            ...match,
            match: {
                ...ended,
                result: {
                    ...ended.result,
                    outcome: 'draw' as const,
                    ending: 'agreed' as const,
                    standing: ended.result.standing.map((row) => ({
                        ...row,
                        points: 1,
                        place: 1 as const,
                    })),
                },
            },
        };
        const stopped = {
            ...match,
            match: {
                ...ended,
                result: {
                    ...ended.result,
                    outcome: 'none' as const,
                    ending: 'stopped' as const,
                    standing: ended.result.standing.map((row) => ({
                        ...row,
                        points: 0,
                        place: null,
                    })),
                },
            },
        };
        const groups = standingGroups(finalRows(draw), LABELS);

        expect(groups).toHaveLength(1);
        expect(groups[0].rows).toHaveLength(2);
        expect(finalTimeline(groups, 'phone').winnerAt).not.toBeNull();
        expect(finalRows(stopped)).toEqual([]);
        expect(
            finalTimeline(standingGroups(finalRows(stopped), LABELS), 'screen')
                .winnerAt,
        ).toBeNull();
    });

    it("keys the computer's row by its own key, nobody's own, and still opens two places (ADR 036)", () => {
        if (match.match?.phase !== 'over' || match.game?.kind !== 'board') {
            throw new Error('a match over is needed');
        }

        const ended = match.match;
        const [winner, loser] = ended.result.standing;
        // The same end with the winner's seat nobody's: the computer won.
        const lost = RoomState.parse({
            ...match,
            game: { ...match.game, computer: 3 },
            match: {
                ...ended,
                sides: ended.sides.map((side) =>
                    side.player_id === winner.player_id
                        ? { ...side, player_id: null }
                        : side,
                ),
                result: {
                    ...ended.result,
                    spent: [],
                    standing: [
                        { ...winner, player_id: null, points: 0 },
                        loser,
                    ],
                },
            },
        });
        const rows = finalRows(lost);

        expect(rows).toEqual([
            { player_id: COMPUTER_SEAT, points: 0, place: 1 },
            { player_id: loser.player_id, points: 0, place: 2 },
        ]);
        expect(
            standingGroups(rows, LABELS).map((group) => group.place),
        ).toEqual([1, 2]);
        // The first place is the computer's: no viewer holds it — not the loser, not the screen.
        expect(holdsFirst(rows, loser.player_id)).toBe(false);
        expect(holdsFirst(rows, null)).toBe(false);
        expect(
            finalTimeline(standingGroups(rows, LABELS), 'phone').winnerAt,
        ).not.toBeNull();
    });
});

describe('a domino game’s final (ADR 038 decision 50)', () => {
    const AYGUN = '01kaqrx3mn4p5q6r7s8t9vwxyz';
    const LEYLA = '01kaqrx5cd6e7f8g9h0jkmnpqr';
    const RESAD = '01kaqrx2ab3c4d5e6f7g8h9jkm';
    const TURAL = '01kaqrx4bc5d6e7f8g9h0jkmnp';

    it('opens the game’s own standing: the winning pair first with what the game gave each, a dry win’s 6 less a taunt', () => {
        const state = RoomState.parse(dominoOver);

        expect(finalRows(state)).toEqual([
            { player_id: AYGUN, points: 5, place: 1 },
            { player_id: LEYLA, points: 6, place: 1 },
            { player_id: RESAD, points: 0, place: 2 },
            { player_id: TURAL, points: 0, place: 2 },
        ]);
        expect(finalSpent(state)).toEqual([{ player_id: AYGUN, count: 1 }]);

        // The pair shares the first place: one group, opened at once.
        const groups = standingGroups(finalRows(state), LABELS);

        expect(groups.map((group) => group.place)).toEqual([1, 2]);
        expect(finalTimeline(groups, 'screen').winnerAt).not.toBeNull();
    });

    it('opens the room’s ranking while a game is live, and the game’s own standing once it is over, on every fixture', () => {
        const fixtures = import.meta.glob<unknown>(
            '../../../../tests/Fixtures/rooms/state.domino.*.json',
            { eager: true, import: 'default' },
        );

        expect(Object.keys(fixtures)).toHaveLength(14);

        for (const [name, data] of Object.entries(fixtures)) {
            const state = RoomState.parse(data);

            if (state.domino?.phase === 'over') {
                expect(finalRows(state), name).toHaveLength(
                    state.domino.result.ending === 'stopped'
                        ? 0
                        : state.domino.result.standing.length,
                );
            } else {
                expect(finalRows(state), name).toBe(state.ranking);
                expect(finalSpent(state), name).toEqual([]);
            }
        }
    });

    it('opens nothing for a game the host stopped, and keys a computer’s row by its seat', () => {
        expect(finalRows(RoomState.parse(dominoStopped))).toEqual([]);
        expect(finalSpent(RoomState.parse(dominoStopped))).toEqual([]);

        const state = RoomState.parse(dominoOver);
        const game = state.domino;

        if (game === null || game.phase !== 'over') {
            throw new Error('over has a result');
        }

        const computer = {
            ...state,
            domino: {
                ...game,
                result: {
                    ...game.result,
                    standing: game.result.standing.map((row) =>
                        row.seat === 2 ? { ...row, player_id: null } : row,
                    ),
                },
            },
        };

        expect(finalRows(computer).map((row) => row.player_id)).toEqual([
            AYGUN,
            LEYLA,
            RESAD,
            `${COMPUTER_SEAT}:2`,
        ]);
    });
});
