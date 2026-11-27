import { seatKey, seatName } from '@/components/rooms/computer';
import { firstName } from '@/components/rooms/domino/places';
import { sideKey, viewerSide } from '@/components/rooms/domino/sides';
import type { Translate } from '@/hooks/use-translation';
import type {
    RoomComputerLevel,
    RoomDomino,
    RoomPlayer,
} from '@/rooms/contract';

/*
 * How a domino page names a seat and a side (docs/frontend.md rule 15
 * "Domino" → "The table", "The strip"): a seat by its player's name — or
 * «Kompüter (Orta)» for the computer's —, a side of one seat by its seat's,
 * a pair by its two first names, and on a seat's phone at `pairs` the
 * viewer's own pair «Biz» and the other «Onlar». Names are the state's, as
 * the server cased them.
 */

type Seats = Pick<RoomDomino, 'seats' | 'sides'>;

/** A seat's name: its player's full name, or its first one (`short`), or the computer's with its level. */
export function dominoSeatName(
    t: Translate,
    players: readonly Pick<RoomPlayer, 'id' | 'name'>[],
    domino: Pick<RoomDomino, 'seats'>,
    level: RoomComputerLevel | null,
    seat: number,
    short = false,
): string {
    const id = domino.seats[seat]?.player_id ?? null;
    const name = seatName(t, players, seatKey(id, seat), level);

    return short && id !== null ? firstName(name) : name;
}

/**
 * A strip row's name by its key (`sideKey()`): a seat's, or a pair's two
 * first names — and, on a phone at `pairs` (`viewer` sitting), «Onlar» for
 * the pair across. A key no side has is named as a seat's key is.
 */
export function dominoSideName(
    t: Translate,
    players: readonly Pick<RoomPlayer, 'id' | 'name'>[],
    domino: Seats,
    level: RoomComputerLevel | null,
    viewer: string | null,
    key: string | null,
): string {
    const side = domino.sides.find((one) => sideKey(domino, one) === key);

    if (side === undefined) {
        return seatName(t, players, key, level);
    }

    if (side.seats.length === 1) {
        return dominoSeatName(t, players, domino, level, side.seats[0]);
    }

    const mine = viewerSide(domino, viewer);

    if (mine !== undefined) {
        return t(
            mine.side === side.side ? 'rooms.rivalry.us' : 'rooms.rivalry.them',
        );
    }

    return side.seats
        .map((seat) => dominoSeatName(t, players, domino, level, seat, true))
        .join(' · ');
}

/**
 * Which half of the strip a side's colour is (ADR 027: sky on the left,
 * orange on the right): the viewer's own side, or — for a watcher and the
 * screen — the side of seat 0, is sky; every other side orange. A seat's
 * chip, a sheet's line and a ring take their side's.
 */
export function sideTone(
    domino: Seats,
    viewer: string | null,
    side: number | null,
): 'left' | 'right' | null {
    if (side === null) {
        return null;
    }

    const own =
        viewerSide(domino, viewer) ??
        domino.sides.find((one) => one.seats.includes(0));

    return own !== undefined && own.side === side ? 'left' : 'right';
}
