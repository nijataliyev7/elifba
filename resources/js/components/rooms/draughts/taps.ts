/*
 * A seat's taps on the board (docs/frontend.md rule 15 "Board games" →
 * "Taps", ADR 034 decisions 11 and 17), the part that is pure: which of the
 * state's legal paths the squares tapped so far begin, which squares may be
 * tapped next, and when the taps are a whole path. Every path is the
 * server's (`match.legal.paths`, the start and every landing): nothing here
 * knows a rule of the game, finds a move or a capture — a square a path
 * does not name is never offered. Reshaped from the contributor's
 * `movesFrom()`, `nextSquares()` and `completeMove()` (pull request #9),
 * over the squares' names.
 */

/** A dark square's official name, `a1`–`h8`. */
export type Square = string;

/** The paths that begin with the squares tapped so far. */
export function pathsFrom(paths: Square[][], taps: Square[]): Square[][] {
    return paths.filter(
        (path) =>
            path.length >= taps.length &&
            taps.every((square, index) => path[index] === square),
    );
}

/** The squares a piece of a path stands on: where a tap lifts it. */
export function startsOf(paths: Square[][]): Set<Square> {
    return new Set(paths.map((path) => path[0]));
}

/** The squares a tap may land on next after the squares tapped so far. */
export function nextSquares(paths: Square[][], taps: Square[]): Set<Square> {
    const next = new Set<Square>();

    if (taps.length === 0) {
        return next;
    }

    for (const path of pathsFrom(paths, taps)) {
        if (path.length > taps.length) {
            next.add(path[taps.length]);
        }
    }

    return next;
}

/**
 * The path the squares tapped name in full, if any. A whole path is never
 * the beginning of another: a capture goes on to its end, so the server
 * sends no shorter path through the same landings.
 */
export function completeMove(
    paths: Square[][],
    taps: Square[],
): Square[] | null {
    if (taps.length < 2) {
        return null;
    }

    return (
        paths.find(
            (path) =>
                path.length === taps.length &&
                taps.every((square, index) => path[index] === square),
        ) ?? null
    );
}

/**
 * What a tap on `square` does to the taps so far: a square the begun path
 * may land on next is added — and a whole path is sent; a piece that begins
 * a path is lifted, or lifted instead of the one before; anything else —
 * the lifted piece again, an empty square, a piece that begins no path —
 * takes the taps back, and with nothing begun does nothing.
 */
export type TapResult = {
    taps: Square[];
    /** The whole path to post, when this tap finished one. */
    send: Square[] | null;
    /** The sound of the tap, or none when it changed nothing. */
    sound: 'tap' | 'undo' | null;
};

export function tapSquare(
    paths: Square[][],
    taps: Square[],
    square: Square,
): TapResult {
    if (nextSquares(paths, taps).has(square)) {
        const next = [...taps, square];

        return { taps: next, send: completeMove(paths, next), sound: 'tap' };
    }

    if (
        startsOf(paths).has(square) &&
        !(taps.length === 1 && taps[0] === square)
    ) {
        return { taps: [square], send: null, sound: 'tap' };
    }

    return {
        taps: [],
        send: null,
        sound: taps.length > 0 ? 'undo' : null,
    };
}

/** «Geri»: the begun path taken back. */
export function untapAll(taps: Square[]): TapResult {
    return { taps: [], send: null, sound: taps.length > 0 ? 'undo' : null };
}
