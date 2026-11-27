import type { Translate } from '@/hooks/use-translation';
import { RoomComputerLevel } from '@/rooms/contract';
import type {
    RoomMatchColor,
    RoomMechanic,
    RoomPlayer,
} from '@/rooms/contract';

/*
 * The computer's seat on a page (docs/frontend.md rule 15 "The computer's
 * seat", ADR 036 decisions 11 and 15), the part that is pure: the key a
 * page draws that seat's row under, the level a game or a choice says, and
 * the words «Kompüter (Orta)». The computer is a seat nobody holds, never
 * a player: the state gives it no id — `player_id` is null on its side and
 * on its row of the match's standing — so nothing here is sent anywhere,
 * and nothing here decides a move, a turn or a result.
 */

/**
 * The key of the seat nobody holds, where a page keys a row by its player:
 * the strip's two sides and the final's two rows of a match (`stripRows()`,
 * `finalRows()`). Never a `room_players.id` — those are ULIDs — and never
 * null, which on a page is a viewer who does not play: the computer's row
 * is nobody's own. It is the page's own word: no request carries it.
 */
export const COMPUTER_SEAT = 'computer';

/**
 * The key a page draws a seat under: its player's row, or the computer's
 * own key — in a domino game, where several seats may be the computer's,
 * with the seat (`computer:2`, ADR 038 decision 57), so each row is its
 * own. A match has one such seat and names none.
 */
export function seatKey(playerId: string | null, seat?: number): string {
    if (playerId !== null) {
        return playerId;
    }

    return seat === undefined ? COMPUTER_SEAT : `${COMPUTER_SEAT}:${seat}`;
}

/** Whether a row's key is a seat the computer plays (`seatKey()`), never a player's. */
export function isComputerKey(key: string | null): boolean {
    return (
        key !== null &&
        (key === COMPUTER_SEAT || key.startsWith(`${COMPUTER_SEAT}:`))
    );
}

/** The levels the computer plays at, lowest first: the contract's (ADR 036 decision 4). */
export const COMPUTER_LEVELS: readonly RoomComputerLevel[] = [
    ...RoomComputerLevel.values,
].sort((a, b) => a - b);

/** The level the computer plays at when it is first chosen: «Orta». */
export const COMPUTER_DEFAULT: RoomComputerLevel = 2;

/**
 * The computer's level in a game or a choice (the state's `game` or
 * `setup`, a past game's row): a board game's or a domino game's
 * `computer`; null for a race, a match of two players, a table of players
 * and no game at all.
 */
export function computerOf(
    game:
        | { mechanic: RoomMechanic; computer?: RoomComputerLevel | null }
        | null
        | undefined,
): RoomComputerLevel | null {
    return game?.computer ?? null;
}

/** «Kompüter (Orta)»: the computer with its level; «Kompüter» where the level is not known. */
export function computerName(
    t: Translate,
    level: RoomComputerLevel | null,
): string {
    return level === null
        ? t('rooms.computer.name')
        : t('rooms.computer.named', {
              level: t(`rooms.computer.level.${level}`),
          });
}

/**
 * The name a page draws for a seat's key: the computer's for the seat
 * nobody holds, else the account's name as the state has it — «Oyunçu» for
 * a name taken out, a row the state does not list and no key at all.
 */
export function seatName(
    t: Translate,
    players: readonly Pick<RoomPlayer, 'id' | 'name'>[],
    key: string | null,
    level: RoomComputerLevel | null,
): string {
    if (isComputerKey(key)) {
        return computerName(t, level);
    }

    return (
        players.find((player) => player.id === key)?.name ??
        t('rooms.show.nameless')
    );
}

/**
 * The colour the computer plays in a choice (ADR 036 decision 2): the seat
 * without a player beside the seat that has one — black while nobody is
 * seated, since the first player to come sits as white (`RoomSeats`). Null
 * while the computer is not chosen.
 */
export function computerSeat(seats: {
    white: string | null;
    black: string | null;
    computer: RoomComputerLevel | null;
}): RoomMatchColor | null {
    if (seats.computer === null) {
        return null;
    }

    if (seats.black === null) {
        return 'black';
    }

    // Two players seated leave it no seat: the server refuses such a choice.
    return seats.white === null ? 'white' : null;
}
