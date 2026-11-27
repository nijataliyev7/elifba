import type { SetupChoice } from '@/components/rooms/catalog';
import { TABLE_SEATS } from '@/components/rooms/domino/places';
import type { RoomTable } from '@/rooms/contract';

/*
 * The host's choice of a domino table on a page (docs/frontend.md rule 15
 * "Domino" → "«Oyunlar» and the start screen", ADR 038 decisions 11–13
 * and 75), the part that is pure: the choice with another table or a
 * player on a seat. The room seats what is left (`RoomTableSeats`): an
 * empty seat takes a present player without one, and only a seat nobody is
 * left for is the computer's — so a page never puts the computer on a seat
 * itself and shows what the state then says.
 */

/** The choice at another table, its seats cut or filled with empty places as the room fits them. */
export function withTable(choice: SetupChoice, table: RoomTable): SetupChoice {
    const seats = choice.seats ?? [];

    return {
        ...choice,
        table,
        seats: Array.from(
            { length: TABLE_SEATS[table] },
            (_, seat) => seats[seat] ?? null,
        ),
    };
}

/**
 * The choice with a present player on a seat: one who sits elsewhere
 * changes places with the seat's player, one who watches takes the seat
 * and its player watches. The same player on the same seat changes
 * nothing.
 */
export function withTableSeat(
    choice: SetupChoice,
    seat: number,
    player: string,
): SetupChoice {
    const seats = [...(choice.seats ?? [])];
    const from = seats.indexOf(player);

    if (from === seat || seat < 0 || seat >= seats.length) {
        return choice;
    }

    if (from >= 0) {
        seats[from] = seats[seat] ?? null;
    }

    seats[seat] = player;

    return { ...choice, seats };
}

/** The seats in the groups «Yerlər» shows them: at `pairs` seats 0 and 2, then 1 and 3; one group otherwise. */
export function seatGroups(table: RoomTable | null, count: number): number[][] {
    if (table === 'pairs' && count === 4) {
        return [
            [0, 2],
            [1, 3],
        ];
    }

    return [Array.from({ length: count }, (_, seat) => seat)];
}
