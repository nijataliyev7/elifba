import type { RoomDominoEnd, RoomDominoLegal } from '@/rooms/contract';

/*
 * A seat's taps in a domino game (docs/frontend.md rule 15 "Domino" →
 * "Taps", ADR 038 decisions 46 and 88), the part that is pure: which tiles
 * are lit, which ends a tile may take and what a tap does — over
 * `mine.legal` alone, which is the server's. Nothing here judges a play:
 * a tile the state does not offer does nothing, and the server answers
 * every play it is sent.
 */

/** A play as it is posted: a hand's first tile alone, or a tile and its end. */
export type PlayPath = [string] | [string, RoomDominoEnd];

/** What a tap did: the tile lifted now (null: none), the play to post (null: none) and the phone's own sound. */
export type DominoTap = {
    picked: string | null;
    send: PlayPath | null;
    sound: 'undo' | null;
};

/** The tiles `legal` offers: lit, raised and strong; every other tile is dimmed and no button. */
export function litTiles(legal: RoomDominoLegal[] | null): Set<string> {
    return new Set((legal ?? []).map((play) => play.tile));
}

/** The ends a tile may take: none for a hand's first tile, one or both; null for a tile `legal` does not offer. */
export function endsOf(
    legal: RoomDominoLegal[] | null,
    tile: string,
): RoomDominoEnd[] | null {
    return legal?.find((play) => play.tile === tile)?.ends ?? null;
}

/**
 * A tap on a tile of the hand: a lit tile that fits one end — or opens the
 * hand — is posted at once; a lit tile that fits both ends lifts, and the
 * line's two targets ask for its end; a tap on another lit tile starts
 * again with it; a tile `legal` does not offer does nothing.
 */
export function tapTile(
    legal: RoomDominoLegal[] | null,
    picked: string | null,
    tile: string,
): DominoTap {
    const ends = endsOf(legal, tile);

    if (ends === null) {
        return { picked, send: null, sound: null };
    }

    if (ends.length === 0) {
        return { picked: null, send: [tile], sound: null };
    }

    if (ends.length === 1) {
        return { picked: null, send: [tile, ends[0]], sound: null };
    }

    return { picked: tile, send: null, sound: null };
}

/** A tap on an end's target while a tile is lifted: the tile at that end, when it fits there. */
export function tapTarget(
    legal: RoomDominoLegal[] | null,
    picked: string | null,
    end: RoomDominoEnd,
): DominoTap {
    if (picked === null || !(endsOf(legal, picked) ?? []).includes(end)) {
        return { picked, send: null, sound: null };
    }

    return { picked: null, send: [picked, end], sound: null };
}

/** «Geri»: the lifted tile goes back, with the phone's `undo`. */
export function putBack(picked: string | null): DominoTap {
    return { picked: null, send: null, sound: picked === null ? null : 'undo' };
}

/** The targets a lifted tile lights on the line: both ends, or none. */
export function targetsOf(
    legal: RoomDominoLegal[] | null,
    picked: string | null,
): RoomDominoEnd[] {
    const ends = picked === null ? null : endsOf(legal, picked);

    return ends !== null && ends.length === 2 ? ends : [];
}
