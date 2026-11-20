import type {
    RoomLastMove,
    RoomMatch,
    RoomMatchColor,
    RoomPiece,
    RoomSide,
} from '@/rooms/contract';

/*
 * What a page needs to draw a match (docs/frontend.md rule 15 "Board
 * games", ADR 034 decisions 5, 28 and 30), the part that is pure: where a
 * square stands on the drawn board, the move list's numbered rows, how
 * long the last move's slide takes and where a crowned man turned, and the
 * clocks as they are shown. A square's name becomes a column and a row only
 * to be drawn; nothing here knows a rule of the game, counts a piece or
 * decides a time: the position, the moves, the captured squares and the
 * clocks are the state's.
 */

/** The files from white's left, and the ranks from white's side. */
export const FILES = ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'] as const;
export const RANKS = [1, 2, 3, 4, 5, 6, 7, 8] as const;

/**
 * Where a square stands on the drawn board, 0–7 from the top left: from
 * white's side unless `flipped` (a seat that plays black sees the board
 * turned round).
 */
export function cellOf(
    square: string,
    flipped: boolean,
): { col: number; row: number } {
    const file = square.charCodeAt(0) - 97;
    const rank = Number(square.slice(1)) - 1;

    return flipped
        ? { col: 7 - file, row: rank }
        : { col: file, row: 7 - rank };
}

/** The square drawn at a column and a row, 0–7 from the top left. */
export function squareAt(col: number, row: number, flipped: boolean): string {
    const file = flipped ? 7 - col : col;
    const rank = flipped ? row : 7 - row;

    return `${FILES[file]}${rank + 1}`;
}

/** Whether a square is dark: `a1` is (ADR 034 decision 5). */
export function isDark(square: string): boolean {
    return (square.charCodeAt(0) - 97 + Number(square.slice(1)) - 1) % 2 === 0;
}

/** The position by square. */
export function piecesBySquare(position: RoomPiece[]): Map<string, RoomPiece> {
    return new Map(position.map((piece) => [piece.square, piece]));
}

/** One numbered row of the record: white's move and black's reply (ADR 034 decision 28). */
export type MoveRow = {
    number: number;
    white: string;
    black: string | null;
};

/**
 * The state's moves, numbered as the record numbers them (6.1.6.2): white's
 * move with black's reply is one number. The lines are the server's, never
 * parsed.
 */
export function moveRows(moves: string[]): MoveRow[] {
    const rows: MoveRow[] = [];

    for (let index = 0; index < moves.length; index += 2) {
        rows.push({
            number: index / 2 + 1,
            white: moves[index],
            black: moves[index + 1] ?? null,
        });
    }

    return rows;
}

/** A leg of the last move's slide, at most; and the whole slide, at most (rule 15 "Motion"). */
export const LEG_MS = 160;
export const SLIDE_MAX_MS = 640;

/** The taken pieces fade this long once the moving piece has landed. */
export const FADE_MS = 120;

/** The slide of the last move: each leg's length and the whole. */
export function slideOf(last: RoomLastMove): { legMs: number; ms: number } {
    const legs = Math.max(1, last.path.length - 1);
    const legMs = Math.min(LEG_MS, Math.floor(SLIDE_MAX_MS / legs));

    return { legMs, ms: legMs * legs };
}

/**
 * Where a man the last move crowned turned into a king: the index in
 * `last.path` of the first landing on its last row (white's 8th, black's
 * 1st) — a man that crowns while capturing goes on as a king (6.1.5.15).
 * Null when the move crowned nobody.
 */
export function crownedAt(
    last: RoomLastMove,
    color: RoomMatchColor,
): number | null {
    if (!last.crowned) {
        return null;
    }

    const row = color === 'white' ? '8' : '1';
    const index = last.path.findIndex(
        (square, at) => at > 0 && square.endsWith(row),
    );

    return index === -1 ? last.path.length - 1 : index;
}

/**
 * A side's clock as the page shows it at `now` (the estimated server
 * clock), for show: the side to move counts down to its flag; the other
 * stands at its `left_ms`. It decides nothing — a flag falls when a state
 * says so.
 */
export function shownLeft(
    match: RoomMatch,
    side: RoomSide,
    now: number | null,
): number {
    if (match.phase !== 'play' || match.turn !== side.color || now === null) {
        return side.left_ms;
    }

    return Math.max(0, Math.min(side.left_ms, match.flag_ms - now));
}

/** Under this the clock shows tenths and turns urgent. */
export const CLOCK_URGENT_MS = 10_000;

/**
 * A clock's figures: «4:52» — whole seconds rounded up, so «0:00» is the
 * flag — and under ten seconds «0:07,4», tenths rounded down, with the
 * locale's decimal mark.
 */
export function clockText(ms: number, decimalMark: string): string {
    const left = Math.max(0, ms);

    if (left < CLOCK_URGENT_MS) {
        const tenths = Math.floor(left / 100);

        return `0:0${Math.floor(tenths / 10)}${decimalMark}${tenths % 10}`;
    }

    const seconds = Math.ceil(left / 1000);

    return `${Math.floor(seconds / 60)}:${String(seconds % 60).padStart(2, '0')}`;
}

/** «5+3»: a level's clock in whole minutes and seconds. */
export function clockLevel(clock: { base_ms: number; increment_ms: number }): {
    minutes: number;
    seconds: number;
} {
    return {
        minutes: Math.round(clock.base_ms / 60_000),
        seconds: Math.round(clock.increment_ms / 1000),
    };
}

/** The side a viewer plays, or null for a watcher. */
export function seatOf(
    match: Pick<RoomMatch, 'sides'>,
    viewer: string | null,
): RoomSide | null {
    return match.sides.find((side) => side.player_id === viewer) ?? null;
}
