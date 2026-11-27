import type {
    RoomDomino,
    RoomMatch,
    RoomRankedPlayer,
    RoomRound,
    RoomState,
} from '@/rooms/contract';

/*
 * A room game's motion (docs/frontend.md rule 15 "Motion", ADR 025), the
 * part that is pure: which moment of the game a state shows, whether the
 * page saw it begin, how far a ranking's rows move between two orders the
 * server gave, and the numbers of a count. Nothing here judges, ranks or
 * adds up: a moment is what the state says, a place is the server's.
 */

/**
 * The moment of a room a state shows: the game, the round's index and its
 * phase — or, in a board game, the ply and the match's phase (ADR 034
 * decision 29), and in a domino game the ply and its phase (ADR 038
 * decision 41): a play is a moment, and so is the sheet after a hand's
 * last play and the next hand's opening.
 */
export type RoomMoment = {
    game: string | null;
    round: number | null;
    phase:
        | 'none'
        | 'lobby'
        | RoomRound['phase']
        | RoomMatch['phase']
        | RoomDomino['phase'];
};

/**
 * What the page draws for a state: nothing (no live state), the lobby
 * before a game, a round of a live race in its phase, a live match at its
 * ply — every move is a moment —, a live domino game at its ply and phase,
 * or a game's end. This is the one place the moment looks at the game's
 * kind (ADR 034 decision 27, ADR 038 decision 44): a live race has its
 * `round`, a live board game its `match`, a live domino game its `domino`.
 */
export function roomMoment(state: RoomState | null): RoomMoment {
    if (state === null) {
        return { game: null, round: null, phase: 'none' };
    }

    const game = state.game;

    if (game === null) {
        return { game: null, round: null, phase: 'lobby' };
    }

    if (game.phase === 'over') {
        return { game: game.id, round: null, phase: 'over' };
    }

    if (state.round !== null) {
        return {
            game: game.id,
            round: state.round.index,
            phase: state.round.phase,
        };
    }

    if (state.match !== null) {
        return {
            game: game.id,
            round: state.match.ply,
            phase: state.match.phase,
        };
    }

    if (state.domino !== null) {
        return {
            game: game.id,
            round: state.domino.ply,
            phase: state.domino.phase,
        };
    }

    return { game: game.id, round: null, phase: 'lobby' };
}

/**
 * A moment's key: at `phase` depth every part of it, at `round` depth the
 * game and the round (so a round's countdown, study, question and reveal
 * are one round), or the game's end.
 */
export function momentKey(
    moment: RoomMoment,
    depth: 'round' | 'phase',
): string {
    const round = moment.round === null ? moment.phase : String(moment.round);

    return depth === 'round'
        ? `${moment.game ?? '-'}|${round}`
        : `${moment.game ?? '-'}|${round}|${moment.phase}`;
}

/**
 * Whether the page saw `current` begin: it is not the moment the page
 * first drew. A page draws the moment it opened on — a reload, a late
 * join, the screen opened mid-game — still and complete, and animates
 * every later one; a state fetched again is the same moment and replays
 * nothing.
 */
export function sawBegin(
    first: RoomMoment,
    current: RoomMoment,
    depth: 'round' | 'phase',
): boolean {
    return momentKey(first, depth) !== momentKey(current, depth);
}

/**
 * Whether the rivalry strip moves (ADR 027 decisions 2 and 14): only in a
 * reveal the page saw begin. During the countdown, the study and the
 * question it stands still, whatever changed, since nothing on it may say
 * anything about the open round; on a reveal the page opened on it is
 * drawn complete and still. A match hides nothing (ADR 034 decision 24):
 * its strip moves at a move the page saw arrive. A domino game's balances
 * change only at a hand's end (ADR 038 decision 48): its strip moves as a
 * sheet the page saw begin rises — a play changes no total, so it moves
 * nothing there.
 */
export function stripMoves(first: RoomMoment, current: RoomMoment): boolean {
    return (
        (current.phase === 'reveal' ||
            current.phase === 'play' ||
            current.phase === 'sheet') &&
        sawBegin(first, current, 'phase')
    );
}

/**
 * How many rows each player moves, from the order of the previous reveal
 * the page showed to the order of this one: their index then minus their
 * index now (positive: up). Both orders are the server's; nothing is
 * sorted here. A player not in the previous order is not in the map: their
 * row comes in instead. Without a previous order nothing moves.
 */
export function rankShift(
    previous: RoomRankedPlayer[] | null,
    current: RoomRankedPlayer[],
): Map<string, number> {
    const shift = new Map<string, number>();

    if (previous === null) {
        return shift;
    }

    const before = new Map(
        previous.map((row, index) => [row.player_id, index]),
    );

    current.forEach((row, index) => {
        const then = before.get(row.player_id);

        if (then !== undefined) {
            shift.set(row.player_id, then - index);
        }
    });

    return shift;
}

/**
 * A count's number at `progress` (0–1) of its way from `from` to `to`,
 * eased out and whole: `from` at the start, exactly `to` at the end and
 * never past it. Both ends are the server's numbers.
 */
export function countUpValue(
    from: number,
    to: number,
    progress: number,
): number {
    if (progress >= 1) {
        return to;
    }

    if (progress <= 0) {
        return from;
    }

    const eased = 1 - (1 - progress) ** 3;
    const value = Math.round(from + (to - from) * eased);

    return to >= from ? Math.min(value, to) : Math.max(value, to);
}

/**
 * A reveal the page saw begin, in ms from its start: the verdict at once,
 * the solution, its right item standing out, then the ranking.
 */
export const REVEAL_MS = {
    solution: 180,
    highlight: 420,
    ranking: 420,
} as const;

/** The delay of the `index`-th item coming in: a short step, the last within `cap` ms. */
export function stagger(index: number, step = 25, cap = 150): number {
    return Math.min(Math.max(0, index) * step, cap);
}
