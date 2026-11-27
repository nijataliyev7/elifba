import {
    COMPUTER_DEFAULT,
    computerName,
    computerSeat,
} from '@/components/rooms/computer';
import { RoomBoardMechanic, RoomDominoMechanic } from '@/rooms/contract';
import type {
    RoomClockLevel,
    RoomComputerLevel,
    RoomGame,
    RoomMatchColor,
    RoomMechanic,
    RoomTable,
} from '@/rooms/contract';
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
    /** A domino game's level: a turn's time (Y5, ADR 038 decision 22). */
    turn_ms?: number;
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
 * clock and its two seats, a player's row or null — and the computer's
 * level while it plays the seat without a player (ADR 036 decision 2),
 * null for two players. A domino game's (ADR 038 decisions 11–14) its
 * level's turn time, its table and its seats in the order of the turns —
 * a player's row, or null for the computer's — and the computer's level
 * while a seat is its.
 */
export type SetupChoice = {
    mechanic: RoomMechanic;
    difficulty: number;
    rounds?: number;
    clock?: RoomClockLevel;
    white?: string | null;
    black?: string | null;
    computer?: RoomComputerLevel | null;
    turn_ms?: number;
    /** Null leaves the table to the number of present players (ADR 038 decision 75). */
    table?: RoomTable | null;
    seats?: (string | null)[];
};

/**
 * A board game's seats as a page draws them: the two colours' players and
 * the computer's level, which then plays the colour without a player
 * (`computerSeat()`).
 */
export type ChoiceSeats = {
    white: string | null;
    black: string | null;
    computer: RoomComputerLevel | null;
};

/** A domino game's table and its seats as a page draws them, and the computer's one level for every seat it plays. */
export type ChoiceTable = {
    table: RoomTable | null;
    seats: (string | null)[];
    computer: RoomComputerLevel | null;
};

/** A game's kind: a race of rounds, a board game or a domino game. */
export type GameKind = RoomGame['kind'];

/**
 * The kind a game is, from its mechanic (ADR 034 decision 1, ADR 038
 * decision 1: the kind follows from the family); a tap's choice has no
 * `kind` until the state answers.
 */
export function kindOf(mechanic: RoomMechanic): GameKind {
    if (RoomBoardMechanic.safeParse(mechanic).success) {
        return 'board';
    }

    return RoomDominoMechanic.safeParse(mechanic).success ? 'domino' : 'race';
}

/**
 * The start screen's parts of a choice (ADR 034 decision 27, the one place
 * the choice looks at the kind): under «Oyun», a race's level and its
 * rounds — «Orta · 10 raund» — or a board game's clock and its two seats —
 * «5+3», and «5+3 · Kompüter (Orta)» while the computer plays one of them
 * (docs/frontend.md rule 15 "Board games", "The computer's seat") — or a
 * domino game's turn time, its table and its seats — «30 san · Para 2×2»,
 * and «· Kompüter (Orta)» once while the computer plays a seat
 * (docs/frontend.md rule 15 "Domino", ADR 038 decision 57).
 */
export type ChoiceParts = {
    /** The control under the level: the rounds' steps, the seats, or the table and its seats. */
    second: 'rounds' | 'seats' | 'table';
    /** The choice's line, beside its game's name. */
    line: string;
    /** A board game's seats, and the computer's level while it plays one; empty for a race. */
    seats: ChoiceSeats;
    /** A domino game's table and seats; null for a race and a board game. */
    table: ChoiceTable | null;
    /**
     * Why the choice cannot start yet: a board game with a seat empty
     * (`rooms.errors.needs_two`) — or, against the computer, with nobody to
     * seat (`rooms.errors.needs_one`, ADR 036 decision 3).
     */
    needs: string | null;
};

export function choiceParts(t: Translate, choice: SetupChoice): ChoiceParts {
    const kind = kindOf(choice.mechanic);

    if (kind === 'domino') {
        const computer = choice.computer ?? null;
        // Undefined seats are a tap's not yet answered: the room's, which the state will say.
        const seated =
            choice.seats === undefined
                ? null
                : choice.seats.filter((seat) => seat !== null).length;

        return {
            second: 'table',
            line: dominoLine(t, choice),
            seats: { white: null, black: null, computer: null },
            table: {
                table: choice.table ?? null,
                seats: choice.seats ?? [],
                computer,
            },
            // One seated player is enough: the computer plays the rest (ADR 038 decision 12).
            needs: seated === 0 ? 'rooms.errors.needs_one' : null,
        };
    }

    if (kind === 'board') {
        const computer = choice.computer ?? null;
        // Undefined seats are a tap's not yet answered: the room's seats, which the state will say.
        const empty = [choice.white, choice.black].filter(
            (seat) => seat === null,
        ).length;

        return {
            second: 'seats',
            line: boardLine(t, choice),
            seats: {
                white: choice.white ?? null,
                black: choice.black ?? null,
                computer,
            },
            // Two players need both seats; against the computer one player is enough.
            needs:
                computer === null
                    ? empty > 0
                        ? 'rooms.errors.needs_two'
                        : null
                    : empty === 2
                      ? 'rooms.errors.needs_one'
                      : null,
            table: null,
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
        seats: { white: null, black: null, computer: null },
        table: null,
        needs: null,
    };
}

/**
 * A domino game's line: its turn's time, its table and, while the
 * computer plays a seat, its level once — «30 san · Para 2×2 · Kompüter
 * (Orta)» (ADR 038 decision 57). What the page does not know yet it leaves
 * out.
 */
function dominoLine(
    t: Translate,
    game: {
        turn_ms?: number;
        table?: RoomTable | null;
        computer?: RoomComputerLevel | null;
    },
): string {
    return [
        ...(game.turn_ms === undefined
            ? []
            : [
                  t('rooms.turn.level', {
                      seconds: Math.round(game.turn_ms / 1000),
                  }),
              ]),
        ...(game.table === undefined || game.table === null
            ? []
            : [t(`rooms.table.${game.table}`)]),
        ...(game.computer === undefined || game.computer === null
            ? []
            : [computerName(t, game.computer)]),
    ].join(' · ');
}

/**
 * A board game's line: its clock and, against the computer, who plays the
 * other seat — «5+3», «5+3 · Kompüter (Orta)».
 */
function boardLine(
    t: Translate,
    game: { clock?: RoomClockLevel; computer?: RoomComputerLevel | null },
): string {
    return [
        ...(game.clock === undefined ? [] : [clockLevelText(t, game.clock)]),
        ...(game.computer === undefined || game.computer === null
            ? []
            : [computerName(t, game.computer)]),
    ].join(' · ');
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
 * `game`, «Keçmiş oyunlar», a closed room's games) — and «Şaşki · 5+3 ·
 * Kompüter (Orta)» for a match against the computer.
 */
export function gameSummary(
    t: Translate,
    game: {
        mechanic: RoomMechanic;
        difficulty: number;
        rounds?: number;
        clock?: RoomClockLevel;
        computer?: RoomComputerLevel | null;
        turn_ms?: number;
        table?: RoomTable | null;
    },
): string {
    if (game.clock !== undefined || game.turn_ms !== undefined) {
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
 * «Şaşki · 5+3» by its clock («Keçmiş oyunlar») — «Şaşki · 5+3 · Kompüter
 * (Orta)» against the computer —, or a domino game's «101 · 30 san · Para
 * 2×2» by its turn's time, with «· Kompüter (Orta)» while it played a seat.
 */
export function gameLabel(
    t: Translate,
    game: {
        mechanic: RoomMechanic;
        difficulty: number;
        clock?: RoomClockLevel;
        computer?: RoomComputerLevel | null;
        turn_ms?: number;
        table?: RoomTable | null;
    },
): string {
    const name = t(`rooms.mechanics.${game.mechanic}.name`);

    if (game.turn_ms !== undefined) {
        return `${name} · ${dominoLine(t, game)}`;
    }

    return game.clock !== undefined
        ? `${name} · ${boardLine(t, game)}`
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
 * seats and computer kept when the choice was of the same kind. A choice of
 * the other kind names none of them: the room fills it — the rounds it
 * keeps, its seats, whether the computer plays
 * (`RoomGameSetupData::validate()`, ADR 034 as built in D5, ADR 036
 * decision 2) — and the page shows what the state then says.
 */
export function withGame(choice: SetupChoice, game: CatalogGame): SetupChoice {
    const difficulty = game.difficulties.includes(choice.difficulty)
        ? choice.difficulty
        : (game.difficulties[0] ?? choice.difficulty);
    const kind = kindOf(game.mechanic);
    // A level of the computer is never carried between a board game and a domino game (ADR 038 decision 75).
    const same = kindOf(choice.mechanic) === kind;

    if (kind === 'race') {
        return { mechanic: game.mechanic, difficulty, rounds: choice.rounds };
    }

    if (kind === 'domino') {
        return withLevel(
            same
                ? {
                      mechanic: game.mechanic,
                      difficulty,
                      table: choice.table,
                      seats: choice.seats,
                      computer: choice.computer,
                  }
                : { mechanic: game.mechanic, difficulty },
            game,
            difficulty,
        );
    }

    return withLevel(
        {
            mechanic: game.mechanic,
            difficulty,
            white: choice.white,
            black: choice.black,
            computer: same ? choice.computer : undefined,
        },
        game,
        difficulty,
    );
}

/** The choice at another level of its game, with a board game's clock or a domino game's turn time of that level. */
export function withLevel(
    choice: SetupChoice,
    game: CatalogGame,
    difficulty: number,
): SetupChoice {
    const level = levelOf(game, difficulty);
    const clock = level?.clock;
    const turn = level?.turn_ms;

    if (turn !== undefined) {
        return { ...choice, difficulty, turn_ms: turn };
    }

    return clock === undefined
        ? { ...choice, difficulty }
        : { ...choice, difficulty, clock };
}

/**
 * The choice with a player on a seat (docs/frontend.md rule 15 "Board
 * games", "The computer's seat"). Between two players, one picked for the
 * other seat changes places. Against the computer: another player on the
 * player's seat plays it instead; the one player picked for the computer's
 * colour takes it, and the computer the other; another player picked for
 * the computer's colour sits there, and the game is two players' again.
 */
export function withSeat(
    choice: SetupChoice,
    color: RoomMatchColor,
    player: string,
): SetupChoice {
    const other = color === 'white' ? 'black' : 'white';
    const own = choice[color] ?? null;
    const across = choice[other] ?? null;
    const computer = choice.computer ?? null;

    if (computer === null) {
        return {
            ...choice,
            [color]: player,
            [other]: across === player ? own : across,
        };
    }

    const computers = computerSeat({
        white: choice.white ?? null,
        black: choice.black ?? null,
        computer,
    });

    return computers === color && across !== null && across !== player
        ? { ...choice, computer: null, [color]: player, [other]: across }
        : { ...choice, [color]: player, [other]: null };
}

/**
 * The choice with the computer on a seat, at the level it had or, first
 * turned on, at «Orta» (ADR 036 decision 15): the player across stays, and
 * with nobody across the seat's own player moves there — so a room of one
 * still has its player seated, with the other colour.
 */
export function withComputer(
    choice: SetupChoice,
    color: RoomMatchColor,
): SetupChoice {
    const other = color === 'white' ? 'black' : 'white';

    return {
        ...choice,
        computer: choice.computer ?? COMPUTER_DEFAULT,
        [color]: null,
        [other]: choice[other] ?? choice[color] ?? null,
    };
}

/**
 * The choice with the computer turned off: two players again. The seat it
 * leaves is the room's to fill (`RoomSeats`), so the page names no seat and
 * shows who sits there once the state says.
 */
export function withoutComputer(choice: SetupChoice): SetupChoice {
    return {
        ...choice,
        computer: null,
        white: choice.white ?? undefined,
        black: choice.black ?? undefined,
    };
}

/**
 * The choice after a tap on a seat's «Kompüter» row (docs/frontend.md rule
 * 15 "The computer's seat"): the computer on that colour (`withComputer()`)
 * — and on the colour it already plays, turned off (`withoutComputer()`),
 * but only while another present player could take its seat. With fewer
 * than two present players the room seats the computer by itself
 * (`RoomSeats`, ADR 036 as amended 2026-11-22), so «off» would change
 * nothing the state could show and the tap would stand
 * (`useRoomChoice()`): the choice stays as it is, as a tap on the player
 * already seated leaves it.
 */
export function withComputerPicked(
    choice: SetupChoice,
    color: RoomMatchColor,
    present: number,
): SetupChoice {
    const plays = computerSeat({
        white: choice.white ?? null,
        black: choice.black ?? null,
        computer: choice.computer ?? null,
    });

    if (plays !== color) {
        return withComputer(choice, color);
    }

    return present < 2 ? choice : withoutComputer(choice);
}

/** The choice with the two colours changed: against the computer, the player takes its colour. */
export function withSeatsSwapped(choice: SetupChoice): SetupChoice {
    return { ...choice, white: choice.black, black: choice.white };
}

/** Whether two choices are the same game, difficulty, rounds, seats, table and computer. */
export function sameChoice(a: SetupChoice, b: SetupChoice): boolean {
    return (
        a.mechanic === b.mechanic &&
        a.difficulty === b.difficulty &&
        a.rounds === b.rounds &&
        (a.white ?? null) === (b.white ?? null) &&
        (a.black ?? null) === (b.black ?? null) &&
        (a.computer ?? null) === (b.computer ?? null) &&
        (a.table ?? null) === (b.table ?? null) &&
        (a.seats ?? []).join(',') === (b.seats ?? []).join(',')
    );
}

/**
 * The body a choice is sent as: a race's `{mechanic, difficulty, rounds}`
 * or a board game's `{mechanic, difficulty, white, black, computer}` — the
 * rounds, the seats or the computer left out while the page does not know
 * them (a choice of the other kind: the room fills them) — never the
 * state's `kind`, `played` or `clock`. A board game's two seats go only as
 * a pair; with the computer (ADR 036 decision 2) at most the one seat a
 * player has, the other colour being the computer's, and with nobody
 * seated none, so the room's seat stands. A board choice that knows whether
 * the computer plays always says so: null turns it off.
 */
export function choiceBody(choice: SetupChoice): Record<string, unknown> {
    const body: Record<string, unknown> = {
        mechanic: choice.mechanic,
        difficulty: choice.difficulty,
    };
    const kind = kindOf(choice.mechanic);

    // A domino game's table (null: the room's to fit), its seats only with a table, the computer's level (ADR 038 decision 75).
    if (kind === 'domino') {
        return {
            ...body,
            ...(choice.table === undefined ? {} : { table: choice.table }),
            ...(choice.seats === undefined ||
            choice.table === undefined ||
            choice.table === null
                ? {}
                : { seats: choice.seats }),
            ...(choice.computer === undefined
                ? {}
                : { computer: choice.computer }),
        };
    }

    if (kind === 'race') {
        return choice.rounds === undefined
            ? body
            : { ...body, rounds: choice.rounds };
    }

    const white = typeof choice.white === 'string' ? choice.white : null;
    const black = typeof choice.black === 'string' ? choice.black : null;
    const pair = white !== null && black !== null ? { white, black } : {};

    if (choice.computer === undefined) {
        return { ...body, ...pair };
    }

    if (choice.computer === null) {
        return { ...body, ...pair, computer: null };
    }

    return {
        ...body,
        ...(white !== null && black === null ? { white } : {}),
        ...(black !== null && white === null ? { black } : {}),
        computer: choice.computer,
    };
}
