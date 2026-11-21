import { describe, expect, it } from 'vite-plus/test';
import {
    choiceBody,
    choiceParts,
    clockLevel,
    gameLabel,
    gameSummary,
    sameChoice,
    withComputer,
    withGame,
    withLevel,
    withoutComputer,
    withSeat,
    withSeatsSwapped,
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

/*
 * The computer in the choice (docs/frontend.md rule 15 "The computer's
 * seat", ADR 036 decisions 2, 3 and 15): the host seats it on a colour, at
 * a level; a room of one can then start; and the choice is sent whole, with
 * at most the one seat a player has.
 */
describe('a choice against the computer (ADR 036)', () => {
    /** Two players seated, the computer off: what the state says of a board game. */
    const two: SetupChoice = { ...board, computer: null };
    /** A room of one: its player white, nobody black. */
    const one: SetupChoice = { ...two, white: 'a', black: null };
    /** The computer chosen: «a» white against it. */
    const against: SetupChoice = { ...one, computer: 2 };
    const seats = (choice: SetupChoice) => [
        choice.white,
        choice.black,
        choice.computer,
    ];

    it('needs one player against the computer, where two players need both seats', () => {
        // A room of one cannot start a match of two, and can start one against the computer.
        expect(choiceParts(t, one).needs).toBe('rooms.errors.needs_two');
        expect(choiceParts(t, against).needs).toBeNull();
        expect(
            choiceParts(t, { ...against, white: null, black: 'a' }).needs,
        ).toBeNull();
        // Nobody to seat: the computer has nobody to play.
        expect(choiceParts(t, { ...against, white: null }).needs).toBe(
            'rooms.errors.needs_one',
        );
        // Two players are as they were.
        expect(choiceParts(t, two).needs).toBeNull();
        // A tap not yet answered names no seat: the state will say.
        expect(choiceParts(t, withGame(race, draughts)).needs).toBeNull();
        expect(
            choiceParts(t, { ...against, white: undefined, black: undefined })
                .needs,
        ).toBeNull();
    });

    it("says the computer and its level in the choice's line and a played game's", () => {
        expect(choiceParts(t, against).line).toBe(
            'rooms.clock.level{"minutes":5,"seconds":3} · rooms.computer.named{"level":"rooms.computer.level.2"}',
        );
        expect(choiceParts(t, two).line).toBe(
            'rooms.clock.level{"minutes":5,"seconds":3}',
        );
        expect(choiceParts(t, against).seats).toEqual({
            white: 'a',
            black: null,
            computer: 2,
        });
        expect(choiceParts(t, race).seats.computer).toBeNull();

        const played = {
            mechanic: 'draughts' as const,
            difficulty: 2,
            clock: { base_ms: 300_000, increment_ms: 3_000 },
        };
        const line =
            'rooms.mechanics.draughts.name · rooms.clock.level{"minutes":5,"seconds":3}';

        // «Şaşki · 5+3 · Kompüter (Çətin)»; between two players, and without the key, «Şaşki · 5+3».
        expect(gameLabel(t, { ...played, computer: 3 })).toBe(
            `${line} · rooms.computer.named{"level":"rooms.computer.level.3"}`,
        );
        expect(gameSummary(t, { ...played, computer: 3 })).toBe(
            gameLabel(t, { ...played, computer: 3 }),
        );
        expect(gameLabel(t, { ...played, computer: null })).toBe(line);
        expect(gameLabel(t, played)).toBe(line);
    });

    it('seats the computer on the colour picked, at «Orta» first, and keeps a player seated', () => {
        // Two players: the computer takes white, black's player stays.
        expect(seats(withComputer(two, 'white'))).toEqual([null, 'a', 2]);
        expect(seats(withComputer(two, 'black'))).toEqual(['b', null, 2]);
        // A room of one: its player moves across rather than out.
        expect(seats(withComputer(one, 'white'))).toEqual([null, 'a', 2]);
        expect(seats(withComputer(one, 'black'))).toEqual(['a', null, 2]);
        // Picked on the player's own colour: the two change colours, the level kept.
        expect(
            seats(withComputer({ ...against, computer: 3 }, 'white')),
        ).toEqual([null, 'a', 3]);
        // Nobody present: it is chosen all the same, and waits for a player.
        expect(
            seats(withComputer({ ...two, white: null, black: null }, 'black')),
        ).toEqual([null, null, 2]);
    });

    it("picks a player for a seat: another on the player's, the same on the computer's, a second for two players", () => {
        // Another player plays the computer instead.
        expect(seats(withSeat(against, 'white', 'b'))).toEqual(['b', null, 2]);
        // The one player takes the computer's colour.
        expect(seats(withSeat(against, 'black', 'a'))).toEqual([null, 'a', 2]);
        // A second player on the computer's colour: the computer is off.
        expect(seats(withSeat(against, 'black', 'b'))).toEqual([
            'a',
            'b',
            null,
        ]);
        // Nobody seated yet: the first player picked plays the computer.
        expect(
            seats(withSeat({ ...against, white: null }, 'black', 'b')),
        ).toEqual([null, 'b', 2]);
        // Two players, as before: one picked for the other seat changes places.
        expect(seats(withSeat(two, 'white', 'a'))).toEqual(['a', 'b', null]);
        expect(seats(withSeat(two, 'white', 'c'))).toEqual(['c', 'a', null]);
    });

    it('changes the colours, and turns the computer off without naming a seat', () => {
        expect(seats(withSeatsSwapped(against))).toEqual([null, 'a', 2]);
        expect(seats(withSeatsSwapped(two))).toEqual(['a', 'b', null]);

        const off = withoutComputer(against);

        // The seat it leaves is the room's to fill: no «needs two» of the page's own.
        expect(seats(off)).toEqual(['a', undefined, null]);
        expect(choiceParts(t, off).needs).toBeNull();
        expect(choiceBody(off)).toEqual({
            mechanic: 'draughts',
            difficulty: 2,
            computer: null,
        });
    });

    it('sends the computer with at most the one seat a player has, and always says whether it plays', () => {
        const head = { mechanic: 'draughts', difficulty: 2 };

        expect(choiceBody(against)).toEqual({
            ...head,
            white: 'a',
            computer: 2,
        });
        expect(choiceBody(withSeatsSwapped(against))).toEqual({
            ...head,
            black: 'a',
            computer: 2,
        });
        // Nobody seated: the room's seat stands.
        expect(choiceBody({ ...against, white: null })).toEqual({
            ...head,
            computer: 2,
        });
        // Two seats and the computer are three: the server refuses them, the page sends neither.
        expect(choiceBody({ ...two, computer: 2 })).toEqual({
            ...head,
            computer: 2,
        });
        // Two players: both seats, and the computer off said.
        expect(choiceBody(two)).toEqual({
            ...head,
            white: 'b',
            black: 'a',
            computer: null,
        });
        expect(choiceBody(one)).toEqual({ ...head, computer: null });
        // Another level or clock keeps the seat and the computer.
        expect(choiceBody(withLevel(against, draughts, 3))).toEqual({
            mechanic: 'draughts',
            difficulty: 3,
            white: 'a',
            computer: 2,
        });
        expect(choiceBody({ ...against, computer: 1 })).toMatchObject({
            white: 'a',
            computer: 1,
        });
        // A board game chosen after a race says nothing of it: the room's stands.
        expect(choiceBody(withGame(race, draughts))).not.toHaveProperty(
            'computer',
        );
        // A race never names it.
        expect(choiceBody(withGame(against, anagram))).toEqual({
            mechanic: 'anagram',
            difficulty: 2,
        });
    });

    it('tells a choice with the computer, or at another level, from one without', () => {
        expect(sameChoice(against, { ...against })).toBe(true);
        expect(sameChoice(against, one)).toBe(false);
        expect(sameChoice(against, { ...against, computer: 3 })).toBe(false);
        // The computer off and the key not known are both no computer.
        expect(sameChoice(one, { ...one, computer: undefined })).toBe(true);
    });
});
