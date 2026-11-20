import type { RoomMechanic } from '@/rooms/contract';

/** A level as the catalog describes it (`RoomGameSetupData::options()`). */
export type SetupLevel = {
    difficulty: number;
    study_ms: number;
    answer_ms: number;
    /** How many items a round asks to recall: a row's length, the lit cells. */
    count: { min: number; max: number } | null;
    grid: { cols: number; rows: number } | null;
    /** B5, `next_in_sequence`: the rules a row follows, its terms and numbers. */
    rules: string[] | null;
    terms: number | null;
    range: { min: number; max: number } | null;
    /** B5, `odd_one_out`: numbers or words, and how many options. */
    source: string | null;
    options: number | null;
    /** B6, a word game: its word's letters. */
    letters: { min: number; max: number } | null;
    /** B6, `spelling`: 1, two spellings may differ in one letter; 2, any two differ in two. */
    swaps: number | null;
};

/** One game of the catalog, with the difficulties it has a level at. */
export type CatalogGame = {
    mechanic: RoomMechanic;
    family: string;
    difficulties: number[];
    levels: SetupLevel[];
};

/** The games a room plays and the bounds of its rounds, in the server's order (`catalog`). */
export type GameSetupOptions = {
    mechanics: CatalogGame[];
    rounds: { min: number; max: number };
};

/**
 * The room's choice of the next game: the state's `setup` without `played`.
 * A race's has its rounds; a board game's (D3, ADR 034 decision 7) has
 * none, and its clock and seats are drawn by its own parts (D4).
 */
export type SetupChoice = {
    mechanic: RoomMechanic;
    difficulty: number;
    rounds?: number;
};

/** A race's rounds, for the lines that count them; a board game has none (D4 writes its clock there). */
export function roundsOf(game: {
    difficulty: number;
    rounds?: number;
}): number {
    return game.rounds ?? 0;
}

/** The round counts offered: every multiple of 5 between the bounds, and the bounds. */
export function roundSteps(bounds: { min: number; max: number }): number[] {
    const steps = new Set([bounds.min, bounds.max]);

    for (
        let step = Math.ceil(bounds.min / 5) * 5;
        step <= bounds.max;
        step += 5
    ) {
        steps.add(step);
    }

    return [...steps].sort((a, b) => a - b);
}

/** The catalog's entry for a game, or the first one when it lists none such. */
export function gameOf(
    options: GameSetupOptions,
    mechanic: RoomMechanic,
): CatalogGame | undefined {
    return (
        options.mechanics.find((one) => one.mechanic === mechanic) ??
        options.mechanics[0]
    );
}

/** The level a game plays at a difficulty, else its first. */
export function levelOf(
    game: CatalogGame,
    difficulty: number,
): SetupLevel | undefined {
    return (
        game.levels.find((one) => one.difficulty === difficulty) ??
        game.levels[0]
    );
}

/**
 * The whole choice with another game: the difficulty and the rounds kept
 * where that game has a level at the difficulty, else its lowest — the
 * request the server checks again (`RoomGameSetupData::rules()`); the page
 * shows what the state then says.
 */
export function withGame(choice: SetupChoice, game: CatalogGame): SetupChoice {
    return {
        mechanic: game.mechanic,
        difficulty: game.difficulties.includes(choice.difficulty)
            ? choice.difficulty
            : (game.difficulties[0] ?? choice.difficulty),
        rounds: choice.rounds,
    };
}

/** Whether two choices are the same game, difficulty and rounds. */
export function sameChoice(a: SetupChoice, b: SetupChoice): boolean {
    return (
        a.mechanic === b.mechanic &&
        a.difficulty === b.difficulty &&
        a.rounds === b.rounds
    );
}
