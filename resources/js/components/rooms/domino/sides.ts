import { seatKey } from '@/components/rooms/computer';
import type { RoomDomino, RoomDominoSide } from '@/rooms/contract';

/*
 * A domino game's sides on a page (docs/frontend.md rule 15 "Domino" →
 * "The strip", ADR 038 decisions 48 and 57), the part that is pure: the
 * key a side's row is drawn under, the side a key names, its players, the
 * viewer's side, and what a hand that ended changed a side's balance by —
 * read from the sheet's lines for the strip's count, as a reveal's results
 * are. Nothing here adds up an account or places a side: the balances, the
 * places and the lines are the server's.
 */

/** The key of a side of two seats (`pairs`): no player stands for a pair alone. */
export const PAIR_KEY = 'pair';

type Seats = Pick<RoomDomino, 'seats'>;

/**
 * The key a side's row is drawn under: a side of one seat is that seat's
 * (its player's id, or the computer's seat — `seatKey()` with the seat), a
 * pair is its own, `pair:1`. Never sent anywhere.
 */
export function sideKey(domino: Seats, side: RoomDominoSide): string {
    if (side.seats.length === 1) {
        const seat = side.seats[0];

        return seatKey(domino.seats[seat]?.player_id ?? null, seat);
    }

    return `${PAIR_KEY}:${side.side}`;
}

/** The side a row's key names, or undefined. */
export function sideOfKey(
    domino: Seats & Pick<RoomDomino, 'sides'>,
    key: string,
): RoomDominoSide | undefined {
    return domino.sides.find((side) => sideKey(domino, side) === key);
}

/** The players of a side, in the order of its seats: a pair's two, none for the computer's seats. */
export function sidePlayers(domino: Seats, side: RoomDominoSide): string[] {
    return side.seats
        .map((seat) => domino.seats[seat]?.player_id ?? null)
        .filter((id): id is string => id !== null);
}

/** The side the viewer sits on, or undefined: a watcher, a host who does not sit, the screen. */
export function viewerSide(
    domino: Seats & Pick<RoomDomino, 'sides'>,
    viewer: string | null,
): RoomDominoSide | undefined {
    const seat =
        viewer === null
            ? undefined
            : domino.seats.find((one) => one.player_id === viewer)?.seat;

    return seat === undefined
        ? undefined
        : domino.sides.find((side) => side.seats.includes(seat));
}

/** The lines of the account that write to a side's balance (ADR 038 decision 70); the others wait, burn or go to the bank. */
const BALANCE_LINES: ReadonlySet<string> = new Set([
    'gained',
    'opened',
    'released',
    'bank_taken',
]);

/**
 * What the hand that just ended added to each side's balance — the sum of
 * its sheet's lines that write to a balance, by side — where the strip's
 * count starts from at a hand's end (`stripChange()`); null outside a
 * hand's end. The balances themselves are the state's.
 */
export function balanceChanges(
    domino: Pick<RoomDomino, 'sheet' | 'sides'>,
): { side: number; points: number }[] | null {
    const sheet = domino.sheet;

    if (sheet === null) {
        return null;
    }

    return domino.sides.map((side) => ({
        side: side.side,
        points: sheet.lines
            .filter(
                (line) =>
                    line.side === side.side && BALANCE_LINES.has(line.line),
            )
            .reduce((sum, line) => sum + line.points, 0),
    }));
}
