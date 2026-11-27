import type { Arrival } from '@/components/rooms/draughts/draughts-board';
import {
    nextShown,
    useArrival as useShownArrival,
} from '@/components/rooms/use-arrival';
import type { RoomMatch, RoomPiece } from '@/rooms/contract';

/** What a page drew last of a match: its game, ply and position, and the move it saw arrive there. */
export type DrawnMatch = {
    game: string;
    ply: number;
    position: RoomPiece[];
    arrival: Arrival | null;
};

/** The move that arrived at `match`'s ply, its piece sliding from the position drawn before. */
function moveOf(game: string, match: RoomMatch) {
    return (before: RoomPiece[]): Arrival | null =>
        match.last === null
            ? null
            : { last: match.last, before, key: `${game}:${match.ply}` };
}

/**
 * What a page draws for `match` after `drawn` (ADR 034 decision 30), as
 * every game played act by act (`nextShown()`): a move arrived when the
 * state's `ply` is one more than the ply the page drew last in the same
 * game, so its piece slides from the position the page drew; a page
 * opened on a position (`drawn` null), a state more than one ply ahead
 * and another game draw still; a state fetched again at the same ply
 * keeps what it drew.
 */
export function nextDrawn(
    drawn: DrawnMatch | null,
    game: string,
    match: RoomMatch,
): DrawnMatch {
    if (drawn !== null && drawn.game === game && drawn.ply === match.ply) {
        return drawn;
    }

    const next = nextShown(
        drawn === null
            ? null
            : {
                  game: drawn.game,
                  ply: drawn.ply,
                  value: drawn.position,
                  arrival: drawn.arrival,
              },
        game,
        match.ply,
        match.position,
        moveOf(game, match),
    );

    return {
        game: next.game,
        ply: next.ply,
        position: next.value,
        arrival: next.arrival,
    };
}

/**
 * Whether the move that arrived is the viewer's own, posted from this page
 * at the ply before (`posted`): its piece already stands where it went, so
 * it does not slide again — whatever the post's receipt said, since an
 * answer lost on the way may hide a move the server took.
 */
export function ownArrival(
    arrival: Arrival | null,
    posted: { ply: number; path: string[] } | null,
    ply: number,
): boolean {
    return (
        arrival !== null &&
        posted !== null &&
        posted.ply + 1 === ply &&
        arrival.last.path.join() === posted.path.join()
    );
}

/**
 * The move the page saw arrive (`nextDrawn()`), or null: the page keeps
 * only what it drew, through the rooms' one `useArrival()`; nothing here
 * keeps a clock or a sound.
 */
export function useArrival(game: string, match: RoomMatch): Arrival | null {
    return useShownArrival(
        game,
        match.ply,
        match.position,
        moveOf(game, match),
    );
}
