import { describe, expect, it } from 'vite-plus/test';
import {
    choiceBody,
    choiceParts,
    clockLevel,
    withGame,
    withLevel,
} from '@/components/rooms/catalog';
import type { CatalogGame, SetupChoice } from '@/components/rooms/catalog';

const t = (key: string, replace?: Record<string, unknown>) =>
    replace === undefined ? key : `${key}${JSON.stringify(replace)}`;

const level = (difficulty: number, clock?: [number, number]) => ({
    difficulty,
    study_ms: 0,
    answer_ms: 0,
    count: null,
    grid: null,
    rules: null,
    terms: null,
    range: null,
    source: null,
    options: null,
    letters: null,
    swaps: null,
    ...(clock === undefined
        ? {}
        : { clock: { base_ms: clock[0], increment_ms: clock[1] } }),
});

const anagram: CatalogGame = {
    mechanic: 'anagram',
    family: 'words',
    difficulties: [1, 2, 3, 4],
    levels: [1, 2, 3, 4].map((difficulty) => level(difficulty)),
};

const draughts: CatalogGame = {
    mechanic: 'draughts',
    family: 'board',
    difficulties: [1, 2, 3],
    levels: [
        level(1, [600_000, 5_000]),
        level(2, [300_000, 3_000]),
        level(3, [180_000, 2_000]),
    ],
};

const race: SetupChoice = {
    mechanic: 'recall_cells',
    difficulty: 4,
    rounds: 15,
};
const board: SetupChoice = {
    mechanic: 'draughts',
    difficulty: 2,
    clock: { base_ms: 300_000, increment_ms: 3_000 },
    white: 'b',
    black: 'a',
};

describe('a choice of another game (ADR 034, as built in D5)', () => {
    it('keeps its own kind’s part and names none of the other’s, which the room fills', () => {
        expect(withGame(race, anagram)).toEqual({
            mechanic: 'anagram',
            difficulty: 4,
            rounds: 15,
        });
        // A board game after a race: no seats of the page's own — the room's stand.
        expect(withGame(race, draughts)).toEqual({
            mechanic: 'draughts',
            difficulty: 1,
            clock: { base_ms: 600_000, increment_ms: 5_000 },
            white: undefined,
            black: undefined,
        });
        expect(choiceBody(withGame(race, draughts))).toEqual({
            mechanic: 'draughts',
            difficulty: 1,
        });
        // A race after a board game: no rounds of the page's own — the room's kept number.
        expect(choiceBody(withGame(board, anagram))).toEqual({
            mechanic: 'anagram',
            difficulty: 2,
        });
    });

    it('sends a board game’s seats only as a pair, and a race’s rounds when it has them', () => {
        expect(choiceBody(board)).toEqual({
            mechanic: 'draughts',
            difficulty: 2,
            white: 'b',
            black: 'a',
        });
        expect(choiceBody({ ...board, black: null })).toEqual({
            mechanic: 'draughts',
            difficulty: 2,
        });
        expect(choiceBody(race)).toEqual({
            mechanic: 'recall_cells',
            difficulty: 4,
            rounds: 15,
        });
    });

    it('moves a board game’s clock with its level, so a tap’s line says the clock tapped', () => {
        expect(withLevel(board, draughts, 3).clock).toEqual({
            base_ms: 180_000,
            increment_ms: 2_000,
        });
        expect(withLevel(race, anagram, 2)).toEqual({ ...race, difficulty: 2 });
        expect(choiceParts(t, withLevel(board, draughts, 3)).line).toBe(
            'rooms.clock.level{"minutes":3,"seconds":2}',
        );
    });

    it('says a seat is missing only when the state says so, never for a tap not yet answered', () => {
        expect(choiceParts(t, { ...board, black: null }).needs).toBe(
            'rooms.errors.needs_two',
        );
        expect(choiceParts(t, withGame(race, draughts)).needs).toBeNull();
        expect(choiceParts(t, withGame(board, anagram)).line).toBe(
            'rooms.difficulty.2',
        );
    });

    it('writes a clock in whole minutes and seconds', () => {
        expect(clockLevel({ base_ms: 300_000, increment_ms: 3_000 })).toEqual({
            minutes: 5,
            seconds: 3,
        });
    });
});
