import { useState } from 'react';

/**
 * What a page drew last of a game played act by act — a match, a domino
 * game: its game, its ply, what it drew there and the act it saw arrive.
 */
export type Shown<T, A> = {
    game: string;
    ply: number;
    value: T;
    arrival: A | null;
};

/**
 * What a page draws at `ply` after `shown` (ADR 025; ADR 034 decision 30,
 * ADR 038 decision 52): an act arrived when the state's ply is one more
 * than the ply the page drew last in the same game — `arrive` then says
 * what it was, from what the page drew before it (null: nothing to
 * draw); a page opened on a position (`shown` null), a state more than
 * one ply ahead and another game draw still; a state fetched again at the
 * same ply keeps what it drew, the same object.
 */
export function nextShown<T, A>(
    shown: Shown<T, A> | null,
    game: string,
    ply: number,
    value: T,
    arrive: (before: T) => A | null,
): Shown<T, A> {
    if (shown !== null && shown.game === game && shown.ply === ply) {
        return shown;
    }

    const arrived =
        shown !== null && shown.game === game && ply === shown.ply + 1;

    return {
        game,
        ply,
        value,
        arrival: arrived ? arrive(shown.value) : null,
    };
}

/**
 * The act the page saw arrive (`nextShown()`), or null: the page keeps
 * only what it drew; nothing here keeps a clock or a sound. A match
 * passes its ply and position; a domino game its ply — and, to see a
 * hand open, the hand's number as the ply.
 */
export function useArrival<T, A>(
    game: string,
    ply: number,
    value: T,
    arrive: (before: T) => A | null,
): A | null {
    const [shown, setShown] = useState<Shown<T, A>>(() =>
        nextShown(null, game, ply, value, arrive),
    );
    const next = nextShown(shown, game, ply, value, arrive);

    if (next !== shown) {
        setShown(next);
    }

    return next.arrival;
}
