import { useState } from 'react';
import type { Arrival } from '@/components/rooms/draughts/draughts-board';
import type { RoomMatch, RoomPiece } from '@/rooms/contract';

/** What a page drew last of a match: its game, ply and position, and the move it saw arrive there. */
export type DrawnMatch = {
    game: string;
    ply: number;
    position: RoomPiece[];
    arrival: Arrival | null;
};

/**
 * What a page draws for `match` after `drawn` (ADR 034 decision 30): a
 * move arrived when the state's `ply` is one more than the ply the page
 * drew last in the same game, so its piece slides from the position the
 * page drew; a page opened on a position (`drawn` null), a state more than
 * one ply ahead and another game draw still; a state fetched again at the
 * same ply keeps what it drew.
 */
export function nextDrawn(
    drawn: DrawnMatch | null,
    game: string,
    match: RoomMatch,
): DrawnMatch {
    if (drawn !== null && drawn.game === game && drawn.ply === match.ply) {
        return drawn;
    }

    const arrived =
        drawn !== null &&
        drawn.game === game &&
        match.ply === drawn.ply + 1 &&
        match.last !== null;

    return {
        game,
        ply: match.ply,
        position: match.position,
        arrival:
            arrived && match.last !== null
                ? {
                      last: match.last,
                      before: drawn.position,
                      key: `${game}:${match.ply}`,
                  }
                : null,
    };
}

/**
 * The move the page saw arrive (`nextDrawn()`), or null: the page keeps
 * only what it drew; nothing here keeps a clock or a sound.
 */
export function useArrival(game: string, match: RoomMatch): Arrival | null {
    const [drawn, setDrawn] = useState<DrawnMatch>(() =>
        nextDrawn(null, game, match),
    );
    const next = nextDrawn(drawn, game, match);

    if (next !== drawn) {
        setDrawn(next);
    }

    return next.arrival;
}
