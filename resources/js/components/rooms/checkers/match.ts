/**
 * A board room's match as `GET /rooms/{room}/match` serves it
 * (`Rooms\Data\RoomMatchStateData`, ADR 034). The page never judges a move:
 * it offers the legal moves the server listed and posts the one tapped.
 */
export type Side = 'w' | 'b';

export type RoomMatch = {
    id: string;
    position: number;
    /** 64 characters, square `row * 8 + col`, row 0 at the top as white sees it: `.`, `w`, `b`, `W`, `B`. */
    board: string;
    turn: Side;
    ply: number;
    white: string;
    black: string;
    last_path: number[] | null;
    over: boolean;
    result: Side | 'draw' | null;
    ending: 'no_moves' | 'resign' | 'left' | 'time' | 'quiet' | 'host' | null;
    /** The legal moves of the side to move, as paths; empty once over. */
    moves: number[][];
    /** Whether those moves are captures: capturing is compulsory. */
    capture: boolean;
    /** Each side's time left at `server_ms`; only the side to move's runs, and none once over. */
    clock: { total_ms: number; white_ms: number; black_ms: number };
};

/** A taunt in a match (ADR 034 decision 11): to the target's phone; with `to` on the big screen. */
export type MatchTaunt = {
    id: string;
    from: string;
    to?: string;
    taunt: string;
    sent_ms: number;
};

export type RoomMatchState = {
    revision: number;
    server_ms: number;
    board_game: 'checkers';
    me: { player_id: string | null; side: Side | null };
    match: RoomMatch | null;
    taunts: {
        /** Whether the viewer, a player of the running match, may throw one. */
        open: boolean;
        /** When the viewer's cooldown ends, by the server's clock. */
        ready_ms: number | null;
        received: MatchTaunt[];
        /** The big screen's: every taunt shown now, with its target. */
        shown: (MatchTaunt & { to: string })[] | null;
    };
};

/**
 * A side's time left at the server moment `nowMs`: the side to move's runs
 * down from the state's `server_ms`, the other side's stands still.
 */
export function timeLeft(
    match: RoomMatch,
    side: Side,
    serverMs: number,
    nowMs: number,
): number {
    const left = side === 'w' ? match.clock.white_ms : match.clock.black_ms;

    if (match.over || side !== match.turn) {
        return left;
    }

    return Math.max(0, left - Math.max(0, nowMs - serverMs));
}

/** «4:05», the way a clock shows minutes and seconds; whole seconds rounded up so 0:00 is the end. */
export function clockLabel(ms: number): string {
    const seconds = Math.ceil(Math.max(0, ms) / 1000);
    const minutes = Math.floor(seconds / 60);

    return `${minutes}:${String(seconds % 60).padStart(2, '0')}`;
}

export function sideOf(piece: string): Side | null {
    if (piece === 'w' || piece === 'W') {
        return 'w';
    }

    if (piece === 'b' || piece === 'B') {
        return 'b';
    }

    return null;
}

export function isKing(piece: string): boolean {
    return piece === 'W' || piece === 'B';
}

/** The legal moves that begin with the squares tapped so far. */
export function movesFrom(moves: number[][], prefix: number[]): number[][] {
    return moves.filter(
        (move) =>
            move.length >= prefix.length &&
            prefix.every((square, index) => move[index] === square),
    );
}

/** The squares a tap may go to next after the squares tapped so far. */
export function nextSquares(moves: number[][], prefix: number[]): Set<number> {
    const next = new Set<number>();

    for (const move of movesFrom(moves, prefix)) {
        if (move.length > prefix.length) {
            next.add(move[prefix.length]);
        }
    }

    return next;
}

/** The legal move the squares tapped name in full, if any: a complete path is never the start of another. */
export function completeMove(
    moves: number[][],
    prefix: number[],
): number[] | null {
    return (
        moves.find(
            (move) =>
                move.length === prefix.length &&
                prefix.every((square, index) => move[index] === square),
        ) ?? null
    );
}

/** How many pieces of a side are on the board. */
export function piecesOf(board: string, side: Side): number {
    let count = 0;

    for (const piece of board) {
        if (sideOf(piece) === side) {
            count++;
        }
    }

    return count;
}
