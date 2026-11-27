import type { RoomTable } from '@/rooms/contract';

/*
 * Where things stand at a domino table on a page (docs/frontend.md rule 15
 * "Domino" → "The table", "The hand"), the part that is pure: the seats
 * round the table from the viewer's own, the rows of a hand, and a
 * pair's partner. It decides nothing of the game: the seats, their order
 * and the tiles are the state's.
 */

/** An edge of the table a seat's chip stands at. */
export type SeatPlace = 'bottom' | 'left' | 'top' | 'right';

/** How many seats a table has. */
export const TABLE_SEATS: Record<RoomTable, number> = {
    two: 2,
    three: 3,
    four: 4,
    pairs: 4,
};

/** The tables in the order «Masa» offers them. */
export const TABLES: RoomTable[] = ['two', 'three', 'four', 'pairs'];

/** The edges going round the table clockwise from the bottom, for each number of seats. */
const ROUND: Record<number, SeatPlace[]> = {
    2: ['bottom', 'top'],
    3: ['bottom', 'left', 'right'],
    4: ['bottom', 'left', 'top', 'right'],
};

/**
 * Each seat's edge, by seat: the viewer's own at the bottom and the others
 * in the order of the turns clockwise — at four the third opposite, which
 * at `pairs` is the partner. A watcher, a host who does not sit and the
 * screen (`own` null) see seat 0 at the bottom.
 */
export function seatPlaces(count: number, own: number | null): SeatPlace[] {
    const round = ROUND[count] ?? ROUND[4];
    const start = own ?? 0;

    return Array.from(
        { length: count },
        (_, seat) => round[(seat - start + count) % count],
    );
}

/** The seats after the viewer's own, in the order of the turns: the phone's row along the table's top. */
export function othersInTurn(count: number, own: number | null): number[] {
    const start = own ?? 0;

    return Array.from(
        { length: count - 1 },
        (_, step) => (start + step + 1) % count,
    );
}

/** A seat's partner at `pairs` — the seat opposite —, none at the other tables. */
export function partnerOf(table: RoomTable, seat: number): number | null {
    return table === 'pairs' ? (seat + 2) % 4 : null;
}

/** A hand's tiles across: seven in a row at 375px. */
export const HAND_ACROSS = 7;

/**
 * A hand in rows, in the order the tiles were dealt and drawn — never
 * sorted: up to seven in one row; eight to fourteen in two, the first row
 * at the bottom and the rest above it; beyond fourteen two rows as long as
 * they must be, which scroll sideways together (`across` > 7).
 */
export function handRows(tiles: readonly string[]): {
    bottom: string[];
    top: string[];
    across: number;
} {
    if (tiles.length <= HAND_ACROSS) {
        return { bottom: [...tiles], top: [], across: tiles.length };
    }

    const across = Math.ceil(tiles.length / 2);

    return {
        bottom: tiles.slice(0, across),
        top: tiles.slice(across),
        across,
    };
}

/** A name's first word: a pair is named by its two first names. */
export function firstName(name: string): string {
    return name.trim().split(/\s+/)[0] ?? name;
}
