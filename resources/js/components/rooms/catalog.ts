import { RoomBoardMechanic } from '@/rooms/contract';
import type { RoomClockLevel, RoomGame, RoomMechanic } from '@/rooms/contract';
import type { Translate } from '@/hooks/use-translation';

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
    /** A board game's level: its clock (D3, ADR 034 decision 6). */
    clock?: RoomClockLevel;
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
 * A race's has its rounds; a board game's (ADR 034 decision 7) its level's
 * clock and its two seats, a player's row or null.
 */
export type SetupChoice = {
    mechanic: RoomMechanic;
    difficulty: number;
    rounds?: number;
    clock?: RoomClockLevel;
    white?: string | null;
    black?: string | null;
};

/** A game's kind: a race of rounds or a board game. */
export type GameKind = RoomGame['kind'];

/**
 * The kind a game is, from its mechanic (ADR 034 decision 1: the kind
 * follows from the family); a tap's choice has no `kind` until the state
 * answers.
 */
export function kindOf(mechanic: RoomMechanic): GameKind {
    return RoomBoardMechanic.safeParse(mechanic).success ? 'board' : 'race';
}

/**
 * The start screen's parts of a choice (ADR 034 decision 27, the one place
 * the choice looks at the kind): under «Oyun», a race's level and its
 * rounds — «Orta · 10 raund» — or a board game's clock and its two seats —
 * «5+3» (docs/frontend.md rule 15 "Board games").
 */
export type ChoiceParts = {
    /** The control under the level: the rounds' steps, or the seats. */
    second: 'rounds' | 'seats';
    /** The choice's line, beside its game's name. */
    line: string;
    /** A board game's seats; empty for a race. */
    seats: { white: string | null; black: string | null };
    /** Why the choice cannot start yet: a board game with a seat empty (`rooms.errors.needs_two`). */
    needs: string | null;
};

export function choiceParts(t: Translate, choice: SetupChoice): ChoiceParts {
    if (kindOf(choice.mechanic) === 'board') {
        return {
            second: 'seats',
            line:
                choice.clock === undefined
                    ? ''
                    : clockLevelText(t, choice.clock),
            seats: {
                white: choice.white ?? null,
                black: choice.black ?? null,
            },
            // Undefined seats are a tap's not yet answered: the room's seats, which the state will say.
            needs:
                choice.white === null || choice.black === null
                    ? 'rooms.errors.needs_two'
                    : null,
        };
    }

    const difficulty = t(`rooms.difficulty.${choice.difficulty}`);

    return {
        second: 'rounds',
        // A tap's race after a board game has no rounds until the state says the room's.
        line:
            choice.rounds === undefined
                ? difficulty
                : `${difficulty} · ${t('rooms.show.rounds', { count: choice.rounds })}`,
        seats: { white: null, black: null },
        needs: null,
    };
}

/** A clock's whole minutes and seconds a move. */
export function clockLevel(clock: RoomClockLevel): {
    minutes: number;
    seconds: number;
} {
    return {
        minutes: Math.round(clock.base_ms / 60_000),
        seconds: Math.round(clock.increment_ms / 1000),
    };
}

/** «5+3»: a clock's minutes and seconds a move. */
export function clockLevelText(t: Translate, clock: RoomClockLevel): string {
    return t('rooms.clock.level', clockLevel(clock));
}

/**
 * A played game's line: «Söz tap · Orta · 10 raund», or a board game's
 * «Şaşki · 5+3» — by its clock, which only a board game has (the state's
 * `game`, «Keçmiş oyunlar», a closed room's games).
 */
export function gameSummary(
    t: Translate,
    game: {
        mechanic: RoomMechanic;
        difficulty: number;
        rounds?: number;
        clock?: RoomClockLevel;
    },
): string {
    if (game.clock !== undefined) {
        return gameLabel(t, game);
    }

    return t('rooms.setup.summary', {
        game: t(`rooms.mechanics.${game.mechanic}.name`),
        difficulty: t(`rooms.difficulty.${game.difficulty}`),
        count: roundsOf(game),
    });
}

/**
 * A game's short line, without rounds: «Söz tap · Orta», or a board game's
 * «Şaşki · 5+3» by its clock («Keçmiş oyunlar»).
 */
export function gameLabel(
    t: Translate,
    game: {
        mechanic: RoomMechanic;
        difficulty: number;
        clock?: RoomClockLevel;
    },
): string {
    const name = t(`rooms.mechanics.${game.mechanic}.name`);

    return game.clock !== undefined
        ? `${name} · ${clockLevelText(t, game.clock)}`
        : t('rooms.index.game', {
              game: name,
              difficulty: t(`rooms.difficulty.${game.difficulty}`),
          });
}

/** A race's rounds, for the lines that count them; a board game has none. */
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
 * The whole choice with another game: the difficulty kept where that game
 * has a level at it, else its lowest; a race's rounds and a board game's
 * seats kept when the choice was of the same kind. A choice of the other
 * kind names neither: the room fills it — the rounds it keeps, its seats
 * (`RoomGameSetupData::validate()`, ADR 034 as built in D5) — and the page
 * shows what the state then says.
 */
export function withGame(choice: SetupChoice, game: CatalogGame): SetupChoice {
    const difficulty = game.difficulties.includes(choice.difficulty)
        ? choice.difficulty
        : (game.difficulties[0] ?? choice.difficulty);

    return kindOf(game.mechanic) === 'race'
        ? { mechanic: game.mechanic, difficulty, rounds: choice.rounds }
        : withLevel(
              {
                  mechanic: game.mechanic,
                  difficulty,
                  white: choice.white,
                  black: choice.black,
              },
              game,
              difficulty,
          );
}

/** The choice at another level of its game, with a board game's clock of that level. */
export function withLevel(
    choice: SetupChoice,
    game: CatalogGame,
    difficulty: number,
): SetupChoice {
    const clock = levelOf(game, difficulty)?.clock;

    return clock === undefined
        ? { ...choice, difficulty }
        : { ...choice, difficulty, clock };
}

/** Whether two choices are the same game, difficulty, rounds and seats. */
export function sameChoice(a: SetupChoice, b: SetupChoice): boolean {
    return (
        a.mechanic === b.mechanic &&
        a.difficulty === b.difficulty &&
        a.rounds === b.rounds &&
        (a.white ?? null) === (b.white ?? null) &&
        (a.black ?? null) === (b.black ?? null)
    );
}

/**
 * The body a choice is sent as: a race's `{mechanic, difficulty, rounds}`
 * or a board game's `{mechanic, difficulty, white, black}` — the rounds or
 * the seats left out while the page does not know them (a choice of the
 * other kind: the room fills them) — never the state's `kind`, `played`
 * or `clock`.
 */
export function choiceBody(choice: SetupChoice): Record<string, unknown> {
    const body: Record<string, unknown> = {
        mechanic: choice.mechanic,
        difficulty: choice.difficulty,
    };

    if (kindOf(choice.mechanic) === 'race') {
        return choice.rounds === undefined
            ? body
            : { ...body, rounds: choice.rounds };
    }

    return typeof choice.white === 'string' && typeof choice.black === 'string'
        ? { ...body, white: choice.white, black: choice.black }
        : body;
}
