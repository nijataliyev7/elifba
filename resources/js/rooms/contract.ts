/**
 * A room's contract (docs/architecture.md §12 "The state", "The race",
 * "Room games", "Games first", "Taunts (T2)", "Live voice", ADR 021
 * decision 6, ADR 023 decision 5, ADR 026 decision 11, ADR 029 decision
 * 15, ADR 031 "The state"): what
 * `GET /rooms/{room}/state` answers (and the `state` of the room page's
 * props), what a device posts as its answer or its taunt and the receipt
 * it gets, and what `room.changed` carries. The schemas are the source; every
 * TypeScript type is inferred from them, never written by hand. The PHP
 * side is `app/Domain/Rooms/Data/*`; both are pinned to
 * `tests/Fixtures/rooms/*.json` (Pest round-trips them, Vitest parses them
 * strictly), so a key added on one side fails until the fixtures and the
 * other side change too. JSON keys are snake_case.
 *
 * The schemas are strict and split by phase, so a key out of place fails
 * them: an account id, an address, the material to study after its phase,
 * the question before its phase, a solution or a result before the
 * reveal. A player is known by `room_players.id` alone. There is no judge,
 * clock or points rule here: the server alone judges, times and scores —
 * a taunt's cost and a balance too.
 *
 * From version 6 (D3, ADR 034 decision 17) a game has a kind: `game` and
 * `setup` are unions by it — a race has its rounds, a board game its clock
 * and two seats — and a board game, live or over, has its `match` where a
 * live race has its `round`. A seat posts its own acts (`RoomAct`): the
 * server alone judges a move and keeps the clock, and sends the legal
 * paths to the seat whose move it is.
 *
 * From version 8 (K3, ADR 036 decision 11) a board game may have one seat
 * played by the computer: its `setup` and `game` say the computer's level
 * (`computer`, null in a game of two players), and that seat's `player_id`
 * — in the match's `sides` and its result's `standing` — is null. The
 * computer is no player: it is in no `players` and no `ranking`.
 *
 * From version 10 (Y5, ADR 038 decision 38) a game has a third kind,
 * domino: `game` and `setup` have a third shape — the turn's time, the
 * table, its seats in the order of the turns (null for the computer's) and
 * the computer's level — and a domino game, live or over, has its `domino`
 * where a race has its `round` and a match its `match`. Unlike a match it
 * hides things: every seat's tiles and the boneyard are counts, a seat's
 * own tiles (`mine`) reach that seat's player alone, and a tile is named
 * only where it is public — the line, the latest play, and from a hand's
 * end every seat's tiles left. A seat posts `play`, `yield` and `back`
 * (`RoomAct`): the server alone judges a play, keeps the turn's time and
 * keeps the account.
 *
 * The board's contract (`resources/js/game/contract.ts`) is another one and
 * shares nothing with this.
 */
import { z } from 'zod';

/** A room's, a game's, a round's id and a player's (`room_players.id`): ULIDs. */
const id = z.ulid();

/** A moment of the server's clock, Unix milliseconds. */
const moment = z.number().int().nonnegative();

const count = z.number().int().nonnegative();

/** An item's id: 8 characters of a–z and 0–9 that carry no position. */
const itemId = z.string().regex(/^[a-z0-9]{8}$/);

export const RoomStatus = z.enum(['open', 'closed']);

export const RoomSummary = z.strictObject({
    id,
    status: RoomStatus,
    locked: z.boolean(),
    /** While the room is open. */
    code: z
        .string()
        .regex(/^\d{6}$/)
        .nullable(),
    /** The host's switch for taunts (T2). */
    taunts: z.boolean(),
    /**
     * Whether the room's big screen is on now (version 4, ADR 028 as
     * amended 2026-11-19): without one, every phone plays the room's cues.
     */
    screen: z.boolean(),
});

export const RoomViewer = z.strictObject({
    /** Null for a host who does not play. */
    player_id: id.nullable(),
    host: z.boolean(),
});

/**
 * A player's standing in the room's live voice (C3, ADR 031 decision 8):
 * `waiting` — not let in yet; `muted` — listens; `open` — listens and may
 * open the microphone. Never whether they are in the voice or speak.
 */
export const RoomVoiceStanding = z.enum(['open', 'muted', 'waiting']);

export const RoomPlayer = z.strictObject({
    id,
    /** The account's name as it is now; null for a row without an account («Oyunçu»). */
    name: z.string().min(1).nullable(),
    present: z.boolean(),
    host: z.boolean(),
    /** While the room's voice is on, for the host and the present players; null otherwise. */
    voice: RoomVoiceStanding.nullable(),
    /**
     * Whether the player's «Hazıram» between games counts (ADR 035 decision
     * 6): derived by the server; false for the host, an absent player and
     * in a closed room.
     */
    ready: z.boolean(),
});

/**
 * The room's live voice (C3, ADR 031 "The state"): whether the host has it
 * on, and whether the viewer's own account accepted the voice's sentence.
 * Null while the instance's voice is off, and in a closed room.
 */
export const RoomVoice = z.strictObject({
    on: z.boolean(),
    agreed: z.boolean(),
});

/** The races a room plays: the six of §12 "Room games" (B3–B6). */
export const RoomRaceMechanic = z.enum([
    'recall_order',
    'recall_cells',
    'next_in_sequence',
    'odd_one_out',
    'anagram',
    'spelling',
]);

/** The board games a room plays (D3, ADR 034): Russian draughts. */
export const RoomBoardMechanic = z.enum(['draughts']);

/** The domino games a room plays (Y5, ADR 038): «101». */
export const RoomDominoMechanic = z.enum(['domino_101']);

/** Every game a room plays; its kind follows from it. */
export const RoomMechanic = z.enum([
    ...RoomRaceMechanic.options,
    ...RoomBoardMechanic.options,
    ...RoomDominoMechanic.options,
]);

export const RoomItem = z.strictObject({
    id: itemId,
    kind: z.enum(['digit', 'letter', 'word', 'number', 'shape', 'cell']),
    /** Cased on the server; content, Azerbaijani in every locale. */
    text: z.string().min(1).nullable(),
    asset: z.string().min(1).nullable(),
    /** `[col, row]`, for a cell of a grid. */
    cell: z.tuple([count, count]).nullable(),
});

const RoomLayout = z.enum(['row', 'grid']);

const RoomGrid = z.strictObject({
    cols: z.number().int().positive(),
    rows: z.number().int().positive(),
});

/** What a memory round shows to study, in the order shown; only in the phase `study`. */
export const RoomStudy = z.strictObject({
    layout: RoomLayout,
    grid: RoomGrid.nullable(),
    items: z.array(RoomItem).min(1),
});

/** The most orders an anagram's question carries (ADR 037 decision 7). */
export const ANAGRAM_ORDERS_MAX = 6;

/**
 * The question; from the phase `answer` on. For a sequence, never in the
 * order to tap. `shown` is what is shown with it and never picked (B5: the
 * row `next_in_sequence` continues; empty for the other mechanics);
 * `items` are what an answer is made of. From ADR 037 an anagram's
 * question also carries its word's `clue` (content, shown as written) and
 * the `orders` «Qarışdır» cycles through — each every item once, the first
 * the order of `items`, no two reading alike, none spelling an accepted
 * word (the server's word: a page never checks or makes one); `null` and
 * `[]` for every other mechanic and an older round.
 */
export const RoomAsk = z
    .strictObject({
        shape: z.enum(['choice', 'sequence', 'set']),
        /** How many items an answer holds. */
        pick: z.number().int().positive(),
        layout: RoomLayout,
        grid: RoomGrid.nullable(),
        shown: z.array(RoomItem),
        items: z.array(RoomItem).min(1),
        clue: z.string().min(1).nullable(),
        orders: z.array(z.array(itemId).min(1)).max(ANAGRAM_ORDERS_MAX),
    })
    .superRefine((ask, context) => {
        if (ask.orders.length === 0) {
            return;
        }

        const ids = ask.items.map((item) => item.id);
        const sorted = [...ids].sort().join(' ');
        const says = new Map(
            ask.items.map((item) => [
                item.id,
                item.text ?? item.asset ?? item.id,
            ]),
        );
        const readings = new Set<string>();

        if (ask.orders[0].join(' ') !== ids.join(' ')) {
            context.addIssue({
                code: 'custom',
                message: "The first order is the items' own.",
                path: ['orders', 0],
            });
        }

        ask.orders.forEach((order, index) => {
            if ([...order].sort().join(' ') !== sorted) {
                context.addIssue({
                    code: 'custom',
                    message: 'An order holds every item once.',
                    path: ['orders', index],
                });
            }

            readings.add(order.map((id) => says.get(id)).join('\u0000'));
        });

        if (readings.size !== ask.orders.length) {
            context.addIssue({
                code: 'custom',
                message: 'No two orders read alike.',
                path: ['orders'],
            });
        }
    });

/**
 * The viewer's own answer, in the reveal. From ADR 037 also how many
 * letters of an anagram's word the viewer bought in the round (0 without
 * any), so the points explain themselves.
 */
export const RoomMine = z.strictObject({
    picked: z.array(itemId).min(1),
    correct: z.boolean(),
    points: z.number().int().min(0).max(20),
    elapsed_ms: count,
    letters: count,
});

/**
 * What the next bought letter costs the answer, as a word the page says and
 * never computes (ADR 037 decision 14): `half` before the first, `rest`
 * before the one that leaves nothing, `none` once nothing is left to lose.
 */
export const RoomLetterPrice = z.enum(['half', 'rest', 'none']);

/**
 * The letters of an anagram's word the viewer bought (ADR 037 decisions
 * 14–16), in the viewer's own state alone, while the round takes answers:
 * the solution's first ids in the word's order, the buyer's own orders of
 * the tiles left (`[]` before a purchase, when the question's serve, and
 * once none is left) and the next letter's price (null once every letter
 * is bought). Null for everyone else, the screen included.
 */
export const RoomLetters = z
    .strictObject({
        bought: z.array(itemId),
        orders: z.array(z.array(itemId).min(1)).max(ANAGRAM_ORDERS_MAX),
        price: RoomLetterPrice.nullable(),
    })
    .superRefine((letters, context) => {
        const bought = new Set(letters.bought);
        const left = letters.orders[0]?.length ?? 0;
        const sets = new Set<string>();

        if (bought.size !== letters.bought.length) {
            context.addIssue({
                code: 'custom',
                message: 'A letter is bought once.',
                path: ['bought'],
            });
        }

        if (
            letters.bought.length === 0 &&
            (letters.orders.length > 0 || letters.price !== 'half')
        ) {
            context.addIssue({
                code: 'custom',
                message:
                    "Before a purchase the question's orders serve and the first letter leaves half.",
                path: ['orders'],
            });
        }

        letters.orders.forEach((order, index) => {
            if (
                order.length !== left ||
                new Set(order).size !== order.length ||
                order.some((tile) => bought.has(tile))
            ) {
                context.addIssue({
                    code: 'custom',
                    message: 'An order lays out the tiles left, each once.',
                    path: ['orders', index],
                });
            }

            sets.add([...order].sort().join(' '));
        });

        if (sets.size > 1) {
            context.addIssue({
                code: 'custom',
                message: 'Every order holds the same tiles.',
                path: ['orders'],
            });
        }
    });

/** A player's result of the round, in the reveal; a player who did not answer has none. */
export const RoomResult = z.strictObject({
    player_id: id,
    correct: z.boolean(),
    points: z.number().int().min(0).max(20),
});

/** A player's taunts counted at this close (T2): each costs the thrower 1 point. */
export const RoomSpent = z.strictObject({
    player_id: id,
    count: z.number().int().positive(),
});

/** Only in the phase `reveal`, once the round is closed. */
export const RoomReveal = z.strictObject({
    /** The canonical ids: for a sequence, the question's ids in the order to tap. */
    solution: z.array(itemId).min(1),
    /**
     * The word of a word game (B6), as the round writes it: an anagram's
     * drawn word in its tiles' capitals, a spelling's right spelling.
     */
    word: z.string().min(1).nullable(),
    mine: RoomMine.nullable(),
    results: z.array(RoomResult),
    /** The taunts counted at this close, for the players with a cost (T2). */
    spent: z.array(RoomSpent),
});

const roundBase = {
    id,
    index: count,
    total: z.number().int().positive(),
    opens_ms: moment,
    answer_from_ms: moment,
    bell_ms: moment,
    present_count: count,
};

/** Before the round has its question, nobody has answered it. */
const unanswered = {
    answered: z.literal(false),
    answered_count: z.literal(0),
};

const answers = {
    answered: z.boolean(),
    answered_count: count,
};

/** Until the round closes, its close is unknown: an early close may come before the bell. */
const open = {
    closes_ms: z.null(),
    next_ms: z.null(),
};

/**
 * The current round of a live game, one shape per phase: the number and the
 * moments before round 0 opens; the material while it is studied; the
 * question from the answer phase; the solution and the results only in the
 * reveal.
 */
export const RoomRound = z.discriminatedUnion('phase', [
    z.strictObject({
        ...roundBase,
        ...unanswered,
        ...open,
        phase: z.literal('countdown'),
        index: z.literal(0),
        study: z.null(),
        ask: z.null(),
        reveal: z.null(),
        letters: z.null(),
    }),
    z.strictObject({
        ...roundBase,
        ...unanswered,
        ...open,
        phase: z.literal('study'),
        study: RoomStudy,
        ask: z.null(),
        reveal: z.null(),
        letters: z.null(),
    }),
    z.strictObject({
        ...roundBase,
        ...answers,
        ...open,
        phase: z.literal('answer'),
        study: z.null(),
        ask: RoomAsk,
        reveal: z.null(),
        /** The viewer's own bought letters (ADR 037): only a present player's, in a round whose question has orders. */
        letters: RoomLetters.nullable(),
    }),
    z.strictObject({
        ...roundBase,
        ...answers,
        phase: z.literal('reveal'),
        closes_ms: moment,
        next_ms: moment,
        study: z.null(),
        ask: RoomAsk,
        reveal: RoomReveal,
        letters: z.null(),
    }),
]);

const difficulty = z.number().int().min(1).max(4);

/** A board game's level: each side's time and what a move adds to it (ADR 034 decision 6). */
export const RoomClockLevel = z.strictObject({
    base_ms: z.number().int().positive(),
    increment_ms: count,
});

/**
 * How strong the computer plays a board game (ADR 036 decision 4): 1
 * «Asan», 2 «Orta», 3 «Çətin». Its own level, never the game's difficulty,
 * which in a board game is its clock.
 */
export const RoomComputerLevel = z.literal([1, 2, 3]);

const raceGameBase = {
    kind: z.literal('race'),
    id,
    mechanic: RoomRaceMechanic,
    difficulty,
    rounds: z.number().int().positive(),
};

const boardGameBase = {
    kind: z.literal('board'),
    id,
    mechanic: RoomBoardMechanic,
    difficulty,
    clock: RoomClockLevel,
    /** The level of the computer, which plays the seat without a player (ADR 036); null: two players. */
    computer: RoomComputerLevel.nullable(),
};

/**
 * A domino game's table (ADR 038 decision 10): two, three or four seats
 * each for itself, or two pairs — seats 0 and 2 against 1 and 3.
 */
export const RoomTable = z.enum(['two', 'three', 'four', 'pairs']);

/** How many seats a table has. */
const tableSeats: Record<z.infer<typeof RoomTable>, number> = {
    two: 2,
    three: 3,
    four: 4,
    pairs: 4,
};

const dominoGameBase = {
    kind: z.literal('domino'),
    id,
    mechanic: RoomDominoMechanic,
    difficulty,
    /** A turn's time: the game's level (ADR 038 decision 22). */
    turn_ms: z.number().int().positive(),
    table: RoomTable,
    /** The balance that ends the game (R25). */
    target: z.number().int().positive(),
    /** The level of the computer, which plays every seat without a player (ADR 038 decision 12); null: none does. */
    computer: RoomComputerLevel.nullable(),
};

const gamePhase = z.enum(['live', 'over']);

/**
 * The room's latest game: a race with its rounds, a board game with its
 * clock and, when it plays, the computer's level, or a domino game with
 * its turn's time, its table, its target and the computer's level.
 */
export const RoomGame = z.discriminatedUnion('kind', [
    z.strictObject({ ...raceGameBase, phase: gamePhase }),
    z.strictObject({ ...boardGameBase, phase: gamePhase }),
    z.strictObject({ ...dominoGameBase, phase: gamePhase }),
]);

/**
 * A row of the ranking: points over closed rounds, a dense place, or none
 * for a player with no points (listed after the places). The page labels a
 * place from its props (`place_labels`).
 */
export const RoomRankedPlayer = z.strictObject({
    player_id: id,
    points: count,
    place: z.number().int().positive().nullable(),
});

/**
 * The room's choice of the next game (G2): what every device shows before
 * the start, and `played` — the latest game is over and was exactly this
 * choice, so a page shows its final rather than the start screen. A race
 * has its rounds; a board game its level's clock and its two seats, a
 * player's row or null while nobody is there to sit (ADR 034 decision 7).
 * With the computer chosen (`computer`, its level; ADR 036 decision 2) at
 * most one seat is a player's and the other colour is the computer's.
 * A domino game (ADR 038 decisions 11–14) has its level's turn time, its
 * table and as many seats as the table has, in the order of the turns —
 * each a player's row, or null for the computer's — and the computer's
 * level exactly while a seat is its.
 * The server derives it; a page compares nothing itself.
 */
export const RoomSetup = z.discriminatedUnion('kind', [
    z.strictObject({
        kind: z.literal('race'),
        mechanic: RoomRaceMechanic,
        difficulty,
        rounds: z.number().int().positive(),
        played: z.boolean(),
    }),
    z.strictObject({
        kind: z.literal('board'),
        mechanic: RoomBoardMechanic,
        difficulty,
        clock: RoomClockLevel,
        white: id.nullable(),
        black: id.nullable(),
        computer: RoomComputerLevel.nullable(),
        played: z.boolean(),
    }),
    z
        .strictObject({
            kind: z.literal('domino'),
            mechanic: RoomDominoMechanic,
            difficulty,
            turn_ms: z.number().int().positive(),
            table: RoomTable,
            seats: z.array(id.nullable()).min(2).max(4),
            computer: RoomComputerLevel.nullable(),
            played: z.boolean(),
        })
        .superRefine((setup, context) => {
            if (
                setup.seats.length !== tableSeats[setup.table] ||
                setup.seats.includes(null) !== (setup.computer !== null)
            ) {
                context.addIssue({
                    code: 'custom',
                    message:
                        "A domino game's choice seats its table, the computer's level said while a seat is its.",
                    path: ['seats'],
                });
            }
        }),
]);

/** A dark square of the board by its official name, `a1`–`h8` (ADR 034 decision 5). */
const square = z
    .string()
    .regex(/^[a-h][1-8]$/)
    .refine(
        (name) => (name.charCodeAt(0) - 97 + Number(name[1]) - 1) % 2 === 0,
        'A dark square.',
    );

/** A move's start and every landing. */
const path = z.array(square).min(2).max(13);

export const RoomMatchColor = z.enum(['white', 'black']);

/**
 * A side: its seat, its time at `server_ms` (or at the end), its pieces, men
 * and kings together. The seat is a player's row, or null: the computer's
 * (ADR 036 decision 11).
 */
export const RoomSide = z.strictObject({
    color: RoomMatchColor,
    player_id: id.nullable(),
    left_ms: count,
    pieces: z.number().int().min(0).max(12),
    kings: z.number().int().min(0).max(12),
});

export const RoomPiece = z.strictObject({
    square,
    color: RoomMatchColor,
    king: z.boolean(),
});

/** The last move: what a page marks and moves along; never parsed from a line. */
export const RoomLastMove = z.strictObject({
    path,
    captured: z.array(square).max(12),
    crowned: z.boolean(),
});

/** The legal paths, for the viewer whose move it is: the only ones a page offers. */
export const RoomLegal = z.strictObject({
    capture: z.boolean(),
    paths: z.array(path).min(1),
});

/**
 * A seat's row of the match's own standing: its points, its place by the
 * outcome. The computer's seat has no player and earns nothing (ADR 036
 * decision 12).
 */
export const RoomMatchStanding = z.strictObject({
    player_id: id.nullable(),
    points: count,
    place: z.union([z.literal(1), z.literal(2)]).nullable(),
});

export const RoomMatchOutcome = z.enum(['white', 'black', 'draw', 'none']);

/** Why a match ended (ADR 034 decision 14). */
export const RoomMatchEnding = z.enum([
    'no_moves',
    'resigned',
    'left',
    'time',
    'agreed',
    'repetition',
    'three_kings',
    'main_road',
    'balance',
    'kings_only',
    'stopped',
]);

/** The endings that are draws: agreed, or declared by the server. */
const drawEndings: ReadonlySet<string> = new Set([
    'agreed',
    'repetition',
    'three_kings',
    'main_road',
    'balance',
    'kings_only',
]);

/**
 * Once a match is over: the outcome, its reason, the two seats' standing —
 * the winner first, both first in a draw, no places without a result — and
 * the taunts counted at its end.
 */
export const RoomMatchResult = z
    .strictObject({
        outcome: RoomMatchOutcome,
        ending: RoomMatchEnding,
        standing: z.array(RoomMatchStanding).length(2),
        spent: z.array(RoomSpent),
    })
    .superRefine((result, context) => {
        const places = result.standing.map((row) => row.place).join(',');
        const expected = { none: ',', draw: '1,1', white: '1,2', black: '1,2' }[
            result.outcome
        ];

        if (
            (result.outcome === 'none') !== (result.ending === 'stopped') ||
            (result.outcome === 'draw') !== drawEndings.has(result.ending) ||
            places !== expected
        ) {
            context.addIssue({
                code: 'custom',
                message:
                    'The places and the ending follow the outcome: the winner first, both first in a draw, none without a result.',
                path: ['standing'],
            });
        }
    });

/** A move's line in the official notation: `c3-d4`, `c3:e5`, `c3:e5:g7`. */
const moveLine = z.string().regex(/^[a-h][1-8]([-:][a-h][1-8])+$/);

const matchBase = {
    /** When the clock starts: the end of the countdown. */
    starts_ms: moment,
    /** White, then black. */
    sides: z.tuple([RoomSide, RoomSide]),
    ply: count,
    /** When the side to move began to think. */
    since_ms: moment,
    position: z.array(RoomPiece).max(24),
    last: RoomLastMove.nullable(),
    moves: z.array(moveLine),
};

/** Before the clock starts: white to move, nothing played or offered. */
const RoomMatchCountdown = z.strictObject({
    ...matchBase,
    phase: z.literal('countdown'),
    turn: z.literal('white'),
    ply: z.literal(0),
    flag_ms: moment,
    last: z.null(),
    moves: z.array(moveLine).length(0),
    legal: z.null(),
    offer: z.null(),
    can_offer: z.literal(false),
    result: z.null(),
});

/** Played: the side to move and its flag; the viewer's legal paths and whether it may offer a draw. */
const RoomMatchPlay = z.strictObject({
    ...matchBase,
    phase: z.literal('play'),
    turn: RoomMatchColor,
    flag_ms: moment,
    legal: RoomLegal.nullable(),
    offer: RoomMatchColor.nullable(),
    can_offer: z.boolean(),
    result: z.null(),
});

/** Over: no side to move, nothing to play or offer, and the result. */
const RoomMatchOver = z.strictObject({
    ...matchBase,
    phase: z.literal('over'),
    turn: z.null(),
    flag_ms: z.null(),
    legal: z.null(),
    offer: z.null(),
    can_offer: z.literal(false),
    result: RoomMatchResult,
});

/**
 * A board game in the state (D3, ADR 034 decision 17), one shape per
 * phase. Nothing in it is secret: every device gets the same board, moves
 * and clocks; `legal` and `can_offer` are the viewer's own.
 */
export const RoomMatch = z.discriminatedUnion('phase', [
    RoomMatchCountdown,
    RoomMatchPlay,
    RoomMatchOver,
]);

/**
 * A domino tile by its name: its two numbers, the larger first — `6-5`,
 * `3-0`, `0-0` (ADR 038 decision 15). Never an index.
 */
const tile = z
    .string()
    .regex(/^[0-6]-[0-6]$/)
    .refine((name) => name[0] >= name[2], 'The larger number first.');

/** An end of the line, as every device draws it («sol uc», «sağ uc»). */
export const RoomDominoEnd = z.enum(['left', 'right']);

/** A seat by its number from 0 in the order of the turns (R3). */
const seatNumber = z.number().int().min(0).max(3);

/** A side by its number: a seat's own, or at `pairs` seats 0 and 2 (side 0) and 1 and 3 (side 1). */
const sideNumber = z.number().int().min(0).max(3);

/** A number of a tile's half, 0–6. */
const pips = z.number().int().min(0).max(6);

/**
 * How a seat stands (ADR 038 decisions 26–27): its player plays, is away
 * (the server plays at once until «Oyuna qayıt»), left the room, or the
 * seat is the computer's.
 */
export const RoomSeatStanding = z.enum(['playing', 'away', 'left', 'computer']);

/** A seat: its player (null for the computer's), its side, how many tiles it holds — a count, never a tile — and how it stands. */
export const RoomDominoSeat = z
    .strictObject({
        seat: seatNumber,
        player_id: id.nullable(),
        side: sideNumber,
        tiles: count,
        standing: RoomSeatStanding,
    })
    .refine(
        (seat) => (seat.player_id === null) === (seat.standing === 'computer'),
        "A seat without a player is the computer's, and only it.",
    );

/**
 * A side and its account (R20–R22): its seats, its balance, whether it is
 * open, the points waiting and the tries, and its dense place by balance.
 */
export const RoomDominoSide = z.strictObject({
    side: sideNumber,
    seats: z.array(seatNumber).min(1).max(2),
    balance: count,
    open: z.boolean(),
    waiting: count,
    tries: count,
    place: z.number().int().positive(),
});

/** Why a seat opens a hand (R6–R9). */
export const RoomOpenerWhy = z.enum([
    'lowest_double',
    'lowest_tile',
    'finished',
    'fewest',
    'blocker',
    'again',
    'given',
]);

/** The hand that is open, or was last: its number from 1, when it opened, who opens it and why. */
export const RoomDominoHand = z.strictObject({
    number: z.number().int().positive(),
    opens_ms: moment,
    opener: seatNumber,
    why: RoomOpenerWhy,
});

/** A tile in the line, as it lies: the number at its left and at its right. */
export const RoomDominoPlaced = z.strictObject({
    tile,
    left: pips,
    right: pips,
});

/** An end of the line and the number it shows. */
const RoomDominoLineEnd = z.strictObject({
    end: RoomDominoEnd,
    number: pips,
});

/** The boneyard, in counts only; closed by R13, never at `four` and `pairs`, which have none (decision 69). */
export const RoomDominoBoneyard = z.strictObject({
    tiles: count,
    reserve: count,
    closed: z.boolean(),
});

/** A seat that drew — how many, never which — or passed after the latest act, by itself (decisions 18, 68). */
const RoomDominoThen = z.strictObject({
    seat: seatNumber,
    drew: count,
    passed: z.boolean(),
});

/**
 * The latest turn act of the hand and what followed it by itself: a play
 * names its tile and its end (none for a hand's first tile); a «Sən aç»
 * names neither and nothing follows it. `stood_in`: the server played it
 * for the seat.
 */
export const RoomDominoLast = z.discriminatedUnion('kind', [
    z.strictObject({
        seat: seatNumber,
        kind: z.literal('play'),
        tile,
        end: RoomDominoEnd.nullable(),
        stood_in: z.boolean(),
        // oxlint-disable-next-line unicorn/no-thenable -- the contract's key (ADR 038 decision 68): an array, never a function
        then: z.array(RoomDominoThen),
    }),
    z.strictObject({
        seat: seatNumber,
        kind: z.literal('yield'),
        tile: z.null(),
        end: z.null(),
        stood_in: z.boolean(),
        // oxlint-disable-next-line unicorn/no-thenable -- the contract's key (ADR 038 decision 68): an array, never a function
        then: z.array(RoomDominoThen).length(0),
    }),
]);

/** A play the seat to move may make: a tile it holds and the ends it fits — none for a hand's first tile. */
export const RoomDominoLegal = z.strictObject({
    tile,
    ends: z.array(RoomDominoEnd).max(2),
});

const mineShape = {
    seat: seatNumber,
    /** In the order dealt and drawn. */
    tiles: z.array(tile),
    /** Drawn after the latest turn act. */
    drawn: z.array(tile),
    /** Only in the seat's own turn; null otherwise, never empty. */
    legal: z.array(RoomDominoLegal).min(1).nullable(),
    /** Whether the seat may give the opening to its partner («Sən aç», R9). */
    may_yield: z.boolean(),
    away: z.boolean(),
};

function heldOnly(
    mine: {
        tiles: string[];
        drawn: string[];
        legal: { tile: string }[] | null;
    },
    context: z.RefinementCtx,
): void {
    const held = new Set(mine.tiles);
    const named = [
        ...mine.drawn,
        ...(mine.legal ?? []).map((play) => play.tile),
    ];

    if (named.some((name) => !held.has(name))) {
        context.addIssue({
            code: 'custom',
            message: 'A seat draws and plays only tiles it holds.',
            path: ['tiles'],
        });
    }
}

/**
 * A seat's own part, for that seat's player alone (ADR 038 decision 6):
 * its tiles, the tiles it just drew, its plays and whether it may give the
 * opening. Null for everyone else — a watcher, a host who does not sit and
 * the screen.
 */
export const RoomDominoMine = z.strictObject(mineShape).superRefine(heldOnly);

/** A seat's own part outside the phase `play`: nothing to play and no opening to give. */
const RoomDominoMineIdle = z
    .strictObject({
        ...mineShape,
        legal: z.null(),
        may_yield: z.literal(false),
    })
    .superRefine(heldOnly);

/** How a hand ended (R14–R19, decision 65). */
export const RoomHandEnding = z.enum(['finished', 'blocked', 'tied', 'void']);

/** A change of the account (decision 70). */
export const RoomLedgerLine = z.enum([
    'gained',
    'opened',
    'waited',
    'released',
    'burnt_rival',
    'burnt_third',
    'bank_added',
    'bank_taken',
    'bank_burnt',
]);

const sheetShape = {
    hand: z.number().int().positive(),
    ending: RoomHandEnding,
    /** Who finished; who made the last play of a blocked or tied hand; a void hand's opener. */
    by: seatNumber,
    winner: sideNumber.nullable(),
    /** Every seat's tiles left — public from the hand's end — and their count by R16. */
    left: z.array(
        z.strictObject({ seat: seatNumber, tiles: z.array(tile), pips: count }),
    ),
    counts: z.array(z.strictObject({ side: sideNumber, pips: count })),
    gain: count,
    /** Every change of the account, in the order it changed; a bank's line names no side. */
    lines: z.array(
        z.strictObject({
            side: sideNumber.nullable(),
            line: RoomLedgerLine,
            points: count,
        }),
    ),
};

function sheetHolds(
    sheet: {
        ending: z.infer<typeof RoomHandEnding>;
        winner: number | null;
        gain: number;
        lines: unknown[];
    },
    context: z.RefinementCtx,
): void {
    const won = sheet.ending === 'finished' || sheet.ending === 'blocked';

    if (won !== (sheet.winner !== null)) {
        context.addIssue({
            code: 'custom',
            message:
                'A finished or blocked hand has its winner, a tied or void hand none.',
            path: ['winner'],
        });
    }

    if (
        sheet.ending === 'void' &&
        (sheet.gain !== 0 || sheet.lines.length > 0)
    ) {
        context.addIssue({
            code: 'custom',
            message: 'A void hand counts nothing.',
            path: ['lines'],
        });
    }
}

/** The sheet in the pause after a hand: who opens the next — a seat, or null while the next deal decides — and when. */
const RoomDominoSheetNext = z
    .strictObject({
        ...sheetShape,
        next: z.strictObject({
            seat: seatNumber.nullable(),
            why: RoomOpenerWhy,
        }),
        until_ms: moment,
    })
    .superRefine(sheetHolds);

/** The last hand's sheet once the game is over: no hand follows (decision 66). */
const RoomDominoSheetLast = z
    .strictObject({ ...sheetShape, next: z.null(), until_ms: z.null() })
    .superRefine(sheetHolds);

/** A seat's row of the game's standing: its side's points less its counted taunts, its side's place. */
export const RoomDominoStanding = z.strictObject({
    player_id: id.nullable(),
    seat: seatNumber,
    side: sideNumber,
    points: count,
    place: z.number().int().positive().nullable(),
});

/**
 * How a domino game ended (decisions 27, 30–32): at its target, with its
 * winning side and whether the win was dry (R26), or stopped without a
 * result; each seat's row, the winning side first; the taunts counted.
 */
export const RoomDominoResult = z
    .strictObject({
        ending: z.enum(['target', 'stopped']),
        winner: sideNumber.nullable(),
        dry: z.boolean(),
        standing: z.array(RoomDominoStanding).min(2).max(4),
        spent: z.array(RoomSpent),
    })
    .superRefine((result, context) => {
        const won = result.ending === 'target';

        if (won !== (result.winner !== null) || (result.dry && !won)) {
            context.addIssue({
                code: 'custom',
                message:
                    'A game that reached its target has a winner, and only it may be dry.',
                path: ['winner'],
            });
        }

        if (
            !won &&
            result.standing.some(
                (row) => row.place !== null || row.points !== 0,
            )
        ) {
            context.addIssue({
                code: 'custom',
                message: 'A stopped game places nobody and gives nothing.',
                path: ['standing'],
            });
        }

        if (
            result.standing.some(
                (row) => row.player_id === null && row.points !== 0,
            )
        ) {
            context.addIssue({
                code: 'custom',
                message: 'The computer earns nothing.',
                path: ['standing'],
            });
        }
    });

const dominoBase = {
    /** When the first hand opens: the end of the countdown. */
    starts_ms: moment,
    /** In the order of the turns. */
    seats: z.array(RoomDominoSeat).min(2).max(4),
    /** In the server's order, by balance and then by side: a page never sorts them. */
    sides: z.array(RoomDominoSide).min(2).max(4),
    bank: count,
    /** The turn acts stored — plays and gives — over the whole game. */
    ply: count,
    /** From the left end to the right, each tile as it lies. */
    line: z.array(RoomDominoPlaced).max(28),
    /** The hand's first tile in `line`. */
    first: count.nullable(),
    /** Empty before the first tile. */
    ends: z.array(RoomDominoLineEnd).max(2),
    boneyard: RoomDominoBoneyard,
    last: RoomDominoLast.nullable(),
};

function dominoHolds(
    domino: {
        seats: { seat: number }[];
        line: unknown[];
        first: number | null;
        ends: unknown[];
        turn: number | null;
        mine: {
            seat: number;
            legal: unknown[] | null;
            may_yield: boolean;
        } | null;
    },
    context: z.RefinementCtx,
): void {
    if (domino.seats.some((seat, index) => seat.seat !== index)) {
        context.addIssue({
            code: 'custom',
            message:
                'A table has two to four seats, in the order of the turns.',
            path: ['seats'],
        });
    }

    const empty = domino.line.length === 0;

    if (
        empty !== (domino.first === null) ||
        empty !== (domino.ends.length === 0)
    ) {
        context.addIssue({
            code: 'custom',
            message:
                'A line has its first tile and its two ends once a tile lies.',
            path: ['line'],
        });
    }

    const mine = domino.mine;

    if (
        mine !== null &&
        (mine.legal !== null || mine.may_yield) &&
        mine.seat !== domino.turn
    ) {
        context.addIssue({
            code: 'custom',
            message:
                'Plays are offered to the seat to move alone, in its turn.',
            path: ['mine'],
        });
    }
}

/** Before the first hand opens at `starts_ms`: nothing dealt or played, no sheet, no result. */
const RoomDominoCountdown = z
    .strictObject({
        phase: z.literal('countdown'),
        ...dominoBase,
        hand: z.null(),
        turn: z.null(),
        since_ms: z.null(),
        flag_ms: z.null(),
        line: z.array(RoomDominoPlaced).length(0),
        first: z.null(),
        ends: z.array(RoomDominoLineEnd).length(0),
        last: z.null(),
        mine: RoomDominoMineIdle.nullable(),
        sheet: z.null(),
        result: z.null(),
    })
    .superRefine(dominoHolds);

/** A hand being played: the hand, the seat to move, since when and its flag; the seat to move's own plays. */
const RoomDominoPlay = z
    .strictObject({
        phase: z.literal('play'),
        ...dominoBase,
        hand: RoomDominoHand,
        turn: seatNumber,
        since_ms: moment,
        flag_ms: moment,
        mine: RoomDominoMine.nullable(),
        sheet: z.null(),
        result: z.null(),
    })
    .superRefine(dominoHolds);

/** The pause after a hand (phase `sheet`): the hand that ended and its sheet, who opens next and when; nobody to move. */
const RoomDominoPause = z
    .strictObject({
        phase: z.literal('sheet'),
        ...dominoBase,
        hand: RoomDominoHand,
        turn: z.null(),
        since_ms: z.null(),
        flag_ms: z.null(),
        mine: RoomDominoMineIdle.nullable(),
        sheet: RoomDominoSheetNext,
        result: z.null(),
    })
    .superRefine(dominoHolds);

/** Over: the result, and the last hand's sheet when the game ended at a hand's end — none when it was stopped inside one. */
const RoomDominoOver = z
    .strictObject({
        phase: z.literal('over'),
        ...dominoBase,
        hand: RoomDominoHand.nullable(),
        turn: z.null(),
        since_ms: z.null(),
        flag_ms: z.null(),
        mine: RoomDominoMineIdle.nullable(),
        sheet: RoomDominoSheetLast.nullable(),
        result: RoomDominoResult,
    })
    .superRefine(dominoHolds);

/**
 * A domino game in the state (Y5, ADR 038 decision 38), one shape per
 * phase, as its viewer may see it: every seat's tiles and the boneyard are
 * counts; `mine` is the seat's own player's alone; tiles are named only
 * where they are public — the line, the latest play and, from a hand's
 * end, `sheet.left`.
 */
export const RoomDomino = z.discriminatedUnion('phase', [
    RoomDominoCountdown,
    RoomDominoPlay,
    RoomDominoPause,
    RoomDominoOver,
]);

/** A taunt's key: one of `content/rooms/taunts.json`; the phrase is the page's own translation. */
const tauntKey = z.string().regex(/^[a-z_]{2,32}$/);

/** A taunt aimed at the viewer (T2): the thrower's row, the key, the server's moment. Never its target. */
export const RoomTauntReceived = z.strictObject({
    id,
    from: id,
    taunt: tauntKey,
    sent_ms: moment,
});

/** A taunt on the big screen (T2): with its target. */
export const RoomTauntShown = z.strictObject({
    id,
    from: id,
    to: id,
    taunt: tauntKey,
    sent_ms: moment,
});

/**
 * A live game's taunts as the viewer may see them (T2, ADR 029 decision 15):
 * whether a throw would be accepted now but for the balance and the
 * cooldown; the viewer's balance (null for a viewer who does not play) and
 * the end of the cooldown; the taunts aimed at the viewer within `show_ms`,
 * newest first; and the same window's taunts with their targets for the
 * screen's request only. The server derives every number.
 */
export const RoomTaunts = z.strictObject({
    open: z.boolean(),
    balance: count.nullable(),
    ready_ms: moment.nullable(),
    received: z.array(RoomTauntReceived).max(3),
    shown: z.array(RoomTauntShown).max(3).nullable(),
});

/** The state's version: a page that meets another reloads itself (`fetchState`). */
export const ROOM_STATE_VERSION = 10;

const stateBase = {
    v: z.literal(ROOM_STATE_VERSION),
    revision: count,
    server_ms: moment,
    room: RoomSummary,
    me: RoomViewer,
    players: z.array(RoomPlayer),
    /** The live game's, over its closed rounds; the room's when no game is live. */
    ranking: z.array(RoomRankedPlayer),
    /** The host's choice of the next game; null in a closed room. */
    setup: RoomSetup.nullable(),
    /** The room's live voice while the instance's voice is on (C3); null in a closed room. */
    voice: RoomVoice.nullable(),
};

/**
 * No game yet; a live race with its current round, or one that is over; a
 * board game with its match, live or over (D3); a domino game with its
 * `domino`, live or over (Y5). A closed room has no voice, and a player has
 * a voice standing exactly while the room's voice is on, for the host and
 * the present players.
 */
export const RoomState = z
    .union([
        z.strictObject({
            ...stateBase,
            game: z.null(),
            round: z.null(),
            match: z.null(),
            domino: z.null(),
            taunts: z.null(),
        }),
        z.strictObject({
            ...stateBase,
            game: z.strictObject({ ...raceGameBase, phase: z.literal('live') }),
            round: RoomRound,
            match: z.null(),
            domino: z.null(),
            /** A live game's taunts (T2); nothing else has any. */
            taunts: RoomTaunts,
        }),
        z.strictObject({
            ...stateBase,
            game: z.strictObject({ ...raceGameBase, phase: z.literal('over') }),
            round: z.null(),
            match: z.null(),
            domino: z.null(),
            taunts: z.null(),
        }),
        z.strictObject({
            ...stateBase,
            game: z.strictObject({
                ...boardGameBase,
                phase: z.literal('live'),
            }),
            round: z.null(),
            match: z.discriminatedUnion('phase', [
                RoomMatchCountdown,
                RoomMatchPlay,
            ]),
            domino: z.null(),
            taunts: RoomTaunts,
        }),
        z.strictObject({
            ...stateBase,
            game: z.strictObject({
                ...boardGameBase,
                phase: z.literal('over'),
            }),
            round: z.null(),
            match: RoomMatchOver,
            domino: z.null(),
            taunts: z.null(),
        }),
        z.strictObject({
            ...stateBase,
            game: z.strictObject({
                ...dominoGameBase,
                phase: z.literal('live'),
            }),
            round: z.null(),
            match: z.null(),
            domino: z.discriminatedUnion('phase', [
                RoomDominoCountdown,
                RoomDominoPlay,
                RoomDominoPause,
            ]),
            taunts: RoomTaunts,
        }),
        z.strictObject({
            ...stateBase,
            game: z.strictObject({
                ...dominoGameBase,
                phase: z.literal('over'),
            }),
            round: z.null(),
            match: z.null(),
            domino: RoomDominoOver,
            taunts: z.null(),
        }),
    ])
    .superRefine((state, context) => {
        if (state.room.status === 'closed' && state.voice !== null) {
            context.addIssue({
                code: 'custom',
                message: 'A closed room has no voice.',
                path: ['voice'],
            });
        }

        state.players.forEach((player, index) => {
            const standing =
                state.voice?.on === true && (player.host || player.present);

            if (standing !== (player.voice !== null)) {
                context.addIssue({
                    code: 'custom',
                    message:
                        'A voice standing only while the voice is on, for the host and the present players.',
                    path: ['players', index, 'voice'],
                });
            }
        });

        // ADR 037: a player's bought letters are the question's own tiles, in a round that sells them.
        const letters =
            state.round?.phase === 'answer' ? state.round.letters : null;

        if (state.round?.phase === 'answer' && letters !== null) {
            const ids = state.round.ask.items.map((item) => item.id);
            const laid = [...letters.bought, ...(letters.orders[0] ?? [])];

            if (
                state.me.player_id === null ||
                state.round.ask.orders.length === 0 ||
                letters.bought.some((tile) => !ids.includes(tile)) ||
                (letters.orders.length > 0 &&
                    [...laid].sort().join(' ') !== [...ids].sort().join(' ')) ||
                (letters.bought.length === ids.length) !==
                    (letters.price === null)
            ) {
                context.addIssue({
                    code: 'custom',
                    message:
                        "A player's own letters of a round whose question has orders: its tiles, bought and left.",
                    path: ['round', 'letters'],
                });
            }
        }
    });

/** `POST /rooms/{room}/answers`: the device's own event id, the round, the items in the order tapped. Never a player. */
export const RoomAnswer = z.strictObject({
    event_id: id,
    round_id: id,
    picked: z.array(itemId).min(1).max(36),
});

/** The receipt of an answer: the same for a right and a wrong one. */
export const RoomAnswerReceipt = z.strictObject({
    status: z.enum(['accepted', 'duplicate', 'answered', 'late']),
    revision: count,
});

/**
 * `POST /rooms/{room}/letters` (ADR 037 decision 12): the device's own event
 * id, the round, and the place in the word of the letter it buys — the next
 * one, so a second tap names one already bought and buys nothing. Never a
 * player, never a letter.
 */
export const RoomLetter = z.strictObject({
    event_id: id,
    round_id: id,
    position: z.number().int().min(0).max(35),
});

/** The receipt of a bought letter: what was done with it; no revision, since nobody else's view changes. */
export const RoomLetterReceipt = z.strictObject({
    status: z.enum(['accepted', 'duplicate', 'bought', 'late']),
});

/** `POST /rooms/{room}/taunts` (T2): the device's own event id, the target's row, a key of the list. Never text, never the thrower. */
export const RoomTaunt = z.strictObject({
    event_id: id,
    target_id: id,
    taunt: tauntKey,
});

/** The receipt of a taunt (T2): what was done with it; the balance comes with the next state. */
export const RoomTauntReceipt = z.strictObject({
    status: z.enum([
        'accepted',
        'duplicate',
        'late',
        'off',
        'cooling',
        'no_points',
    ]),
    revision: count,
});

/** A domino play's `path`: `[tile]` for a hand's first tile, else `[tile, end]` (ADR 038 decision 16). */
const playPath = z.union([z.tuple([tile]), z.tuple([tile, RoomDominoEnd])]);

/**
 * `POST /rooms/{room}/acts` (D3, ADR 034 decision 9): a seat's own act in
 * the game the device saw (`game_id`, as an answer names its round) — a
 * move with its path, a draw offer or its answer at the ply the device saw,
 * or resigning. From Y5 (ADR 038 decision 16) a domino seat's: a play with
 * its tile and end, «Sən aç» (`yield`) at the ply the device saw, and
 * «Oyuna qayıt» (`back`) at none. Never a player, never `leave`, which the
 * server writes.
 */
export const RoomAct = z.discriminatedUnion('kind', [
    z.strictObject({
        event_id: id,
        game_id: id,
        kind: z.literal('move'),
        ply: count,
        path,
    }),
    z.strictObject({
        event_id: id,
        game_id: id,
        kind: z.literal('offer'),
        ply: count,
    }),
    z.strictObject({
        event_id: id,
        game_id: id,
        kind: z.literal('accept'),
        ply: count,
    }),
    z.strictObject({
        event_id: id,
        game_id: id,
        kind: z.literal('decline'),
        ply: count,
    }),
    z.strictObject({ event_id: id, game_id: id, kind: z.literal('resign') }),
    z.strictObject({
        event_id: id,
        game_id: id,
        kind: z.literal('play'),
        ply: count,
        path: playPath,
    }),
    z.strictObject({
        event_id: id,
        game_id: id,
        kind: z.literal('yield'),
        ply: count,
    }),
    z.strictObject({ event_id: id, game_id: id, kind: z.literal('back') }),
]);

/** The receipt of an act: what was done with it; the match comes with the next state. */
export const RoomActReceipt = z.strictObject({
    status: z.enum(['accepted', 'duplicate', 'late']),
    revision: count,
});

/** `room.changed` on `private-room.{id}`: the revision and nothing else. */
export const RoomChangedEvent = z.strictObject({
    v: z.literal(1),
    revision: count,
});

export type RoomStatus = z.infer<typeof RoomStatus>;
export type RoomSummary = z.infer<typeof RoomSummary>;
export type RoomViewer = z.infer<typeof RoomViewer>;
export type RoomPlayer = z.infer<typeof RoomPlayer>;
export type RoomVoiceStanding = z.infer<typeof RoomVoiceStanding>;
export type RoomVoice = z.infer<typeof RoomVoice>;
export type RoomMechanic = z.infer<typeof RoomMechanic>;
export type RoomRaceMechanic = z.infer<typeof RoomRaceMechanic>;
export type RoomBoardMechanic = z.infer<typeof RoomBoardMechanic>;
export type RoomClockLevel = z.infer<typeof RoomClockLevel>;
export type RoomComputerLevel = z.infer<typeof RoomComputerLevel>;
export type RoomMatchColor = z.infer<typeof RoomMatchColor>;
export type RoomSide = z.infer<typeof RoomSide>;
export type RoomPiece = z.infer<typeof RoomPiece>;
export type RoomLastMove = z.infer<typeof RoomLastMove>;
export type RoomLegal = z.infer<typeof RoomLegal>;
export type RoomMatchStanding = z.infer<typeof RoomMatchStanding>;
export type RoomMatchResult = z.infer<typeof RoomMatchResult>;
export type RoomMatch = z.infer<typeof RoomMatch>;
export type RoomDominoMechanic = z.infer<typeof RoomDominoMechanic>;
export type RoomTable = z.infer<typeof RoomTable>;
export type RoomDominoEnd = z.infer<typeof RoomDominoEnd>;
export type RoomSeatStanding = z.infer<typeof RoomSeatStanding>;
export type RoomDominoSeat = z.infer<typeof RoomDominoSeat>;
export type RoomDominoSide = z.infer<typeof RoomDominoSide>;
export type RoomOpenerWhy = z.infer<typeof RoomOpenerWhy>;
export type RoomDominoHand = z.infer<typeof RoomDominoHand>;
export type RoomDominoPlaced = z.infer<typeof RoomDominoPlaced>;
export type RoomDominoBoneyard = z.infer<typeof RoomDominoBoneyard>;
export type RoomDominoLast = z.infer<typeof RoomDominoLast>;
export type RoomDominoLegal = z.infer<typeof RoomDominoLegal>;
export type RoomDominoMine = z.infer<typeof RoomDominoMine>;
export type RoomHandEnding = z.infer<typeof RoomHandEnding>;
export type RoomLedgerLine = z.infer<typeof RoomLedgerLine>;
export type RoomDominoStanding = z.infer<typeof RoomDominoStanding>;
export type RoomDominoResult = z.infer<typeof RoomDominoResult>;
export type RoomDomino = z.infer<typeof RoomDomino>;
/** The sheet of the hand that just ended: in the pause with who opens next, at the end without. */
export type RoomDominoSheet = NonNullable<RoomDomino['sheet']>;
export type RoomAct = z.infer<typeof RoomAct>;
export type RoomActReceipt = z.infer<typeof RoomActReceipt>;
export type RoomItem = z.infer<typeof RoomItem>;
export type RoomStudy = z.infer<typeof RoomStudy>;
export type RoomAsk = z.infer<typeof RoomAsk>;
export type RoomMine = z.infer<typeof RoomMine>;
export type RoomLetterPrice = z.infer<typeof RoomLetterPrice>;
export type RoomLetters = z.infer<typeof RoomLetters>;
export type RoomLetter = z.infer<typeof RoomLetter>;
export type RoomLetterReceipt = z.infer<typeof RoomLetterReceipt>;
export type RoomResult = z.infer<typeof RoomResult>;
export type RoomReveal = z.infer<typeof RoomReveal>;
export type RoomSpent = z.infer<typeof RoomSpent>;
export type RoomTaunts = z.infer<typeof RoomTaunts>;
export type RoomTauntReceived = z.infer<typeof RoomTauntReceived>;
export type RoomTauntShown = z.infer<typeof RoomTauntShown>;
export type RoomTaunt = z.infer<typeof RoomTaunt>;
export type RoomTauntReceipt = z.infer<typeof RoomTauntReceipt>;
export type RoomRound = z.infer<typeof RoomRound>;
export type RoomGame = z.infer<typeof RoomGame>;
/** A race of rounds: the game a round is of. */
export type RoomRaceGame = Extract<RoomGame, { kind: 'race' }>;
export type RoomBoardGame = Extract<RoomGame, { kind: 'board' }>;
export type RoomDominoGame = Extract<RoomGame, { kind: 'domino' }>;
export type RoomSetup = z.infer<typeof RoomSetup>;
export type RoomRankedPlayer = z.infer<typeof RoomRankedPlayer>;
export type RoomState = z.infer<typeof RoomState>;
export type RoomAnswer = z.infer<typeof RoomAnswer>;
export type RoomAnswerReceipt = z.infer<typeof RoomAnswerReceipt>;
export type RoomChangedEvent = z.infer<typeof RoomChangedEvent>;
