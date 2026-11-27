import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import {
    COMPUTER_DEFAULT,
    COMPUTER_LEVELS,
    COMPUTER_SEAT,
    computerName,
    computerOf,
    computerSeat,
    isComputerKey,
    seatKey,
    seatName,
} from './computer';

/*
 * The computer's seat on a page (docs/frontend.md rule 15 "The computer's
 * seat", ADR 036 decisions 11 and 15): a seat nobody holds has a key of the
 * page's own, a level the game or the choice says, and the words «Kompüter
 * (Orta)» — never a player's id, name or row.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): RoomState {
    return RoomState.parse(
        fixtures[`../../../../tests/Fixtures/rooms/state.${name}.json`],
    );
}

const t = (key: string, replace?: Record<string, unknown>) =>
    replace === undefined ? key : `${key}${JSON.stringify(replace)}`;

describe("the computer's seat", () => {
    const computer = state('match.computer');
    const chosen = state('match.computer.chosen');

    it("keys a seat by its player, and the seat nobody holds by a key that is no player's", () => {
        const resad = computer.players[0].id;

        expect(seatKey(resad)).toBe(resad);
        expect(seatKey(null)).toBe(COMPUTER_SEAT);
        // A row's id is a ULID of 26 characters: the key can never be one.
        expect(/^[0-9a-hjkmnp-tv-z]{26}$/i.test(COMPUTER_SEAT)).toBe(false);
        expect(computer.players.map((player) => player.id)).not.toContain(
            COMPUTER_SEAT,
        );
    });

    it('reads the level from a board game or its choice, and none from a race or a match of two', () => {
        expect(computerOf(computer.game)).toBe(2);
        expect(computerOf(chosen.setup)).toBe(2);
        expect(computerOf(state('match.play').game)).toBeNull();
        expect(computerOf(state('match.chosen').setup)).toBeNull();
        expect(computerOf(state('answer').game)).toBeNull();
        expect(computerOf(state('lobby').setup)).toBeNull();
        expect(computerOf(null)).toBeNull();
        expect(computerOf(undefined)).toBeNull();
    });

    it('offers three levels, lowest first, and turns on at the middle one', () => {
        expect(COMPUTER_LEVELS).toEqual([1, 2, 3]);
        expect(COMPUTER_DEFAULT).toBe(2);
    });

    it('names the computer with its level, and without one where none is known', () => {
        expect(computerName(t, 2)).toBe(
            'rooms.computer.named{"level":"rooms.computer.level.2"}',
        );
        expect(computerName(t, null)).toBe('rooms.computer.name');
    });

    it("names a seat by its key: the computer's, a player's as the state has it, «Oyunçu» otherwise", () => {
        const players = [
            { id: 'a', name: 'Aygün Məmmədova' },
            { id: 'b', name: null },
        ];

        expect(seatName(t, players, COMPUTER_SEAT, 3)).toBe(
            'rooms.computer.named{"level":"rooms.computer.level.3"}',
        );
        expect(seatName(t, players, 'a', 3)).toBe('Aygün Məmmədova');
        // A name taken out, a row the state does not list, and no key at all.
        expect(seatName(t, players, 'b', 3)).toBe('rooms.show.nameless');
        expect(seatName(t, players, 'gone', null)).toBe('rooms.show.nameless');
        expect(seatName(t, players, null, 3)).toBe('rooms.show.nameless');
    });

    it('plays the colour without a player — black while nobody is seated', () => {
        const seats = (white: string | null, black: string | null) => ({
            white,
            black,
            computer: 2 as const,
        });

        expect(computerSeat(seats('a', null))).toBe('black');
        expect(computerSeat(seats(null, 'a'))).toBe('white');
        expect(computerSeat(seats(null, null))).toBe('black');
        // Two players seated leave it no seat; and without the computer there is none.
        expect(computerSeat(seats('a', 'b'))).toBeNull();
        expect(
            computerSeat({ white: 'a', black: null, computer: null }),
        ).toBeNull();
    });
});

describe('the computer’s seats in a domino game (ADR 038 decision 57)', () => {
    it('keys each seat nobody holds by its seat, and a match’s one as before', () => {
        expect(seatKey(null, 2)).toBe(`${COMPUTER_SEAT}:2`);
        expect(seatKey(null, 0)).toBe(`${COMPUTER_SEAT}:0`);
        expect(seatKey(null)).toBe(COMPUTER_SEAT);
        expect(seatKey('01kaqrx3mn4p5q6r7s8t9vwxyz', 1)).toBe(
            '01kaqrx3mn4p5q6r7s8t9vwxyz',
        );

        expect(isComputerKey(seatKey(null, 3))).toBe(true);
        expect(isComputerKey(COMPUTER_SEAT)).toBe(true);
        expect(isComputerKey('01kaqrx3mn4p5q6r7s8t9vwxyz')).toBe(false);
        expect(isComputerKey('pair:1')).toBe(false);
        expect(isComputerKey(null)).toBe(false);
    });

    it('names a computer’s seat «Kompüter (Orta)» whatever its number, and says a domino choice’s level', () => {
        const t = (key: string, replace?: Record<string, unknown>) =>
            replace === undefined ? key : `${key}${JSON.stringify(replace)}`;

        expect(seatName(t, [], seatKey(null, 3), 2)).toBe(
            'rooms.computer.named{"level":"rooms.computer.level.2"}',
        );
        expect(computerOf(state('domino.chosen').setup)).toBe(2);
        expect(computerOf(state('domino.play').game)).toBeNull();
    });
});
