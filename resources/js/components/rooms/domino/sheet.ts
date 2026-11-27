import type { RoomDomino, RoomLedgerLine } from '@/rooms/contract';

/*
 * A hand's sheet on a page (docs/frontend.md rule 15 "Domino" → "The
 * sheet", ADR 038 decision 49), the part that is pure: the sheet's
 * sentences as keys and the state's numbers, in its order. Nothing here
 * counts a pip, a line of the account or a balance: each number is the
 * sheet's or the side's, and a page writes none of its own — but how far
 * a balance stands from the target.
 */

/** A sentence of the sheet: its key and what fills it. */
export type SheetWords = {
    key: string;
    params: Record<string, string | number>;
};

/** A line of the account, as the sheet says it: beside its side's mark, burnt in words with `Flame`. */
export type SheetLine = SheetWords & {
    side: number | null;
    line: RoomLedgerLine;
    burnt: boolean;
};

export type SheetView = {
    hand: number;
    /** How the hand ended: «Əli :name bitirdi», «Əl bağlandı», «Əl bərabər bağlandı», «Cavab yoxdur — …». */
    how: SheetWords;
    /** Every seat's tiles left, face up, and their count by R16, as the sheet has them. */
    left: { seat: number; tiles: string[]; pips: number; loneBlank: boolean }[];
    /** Each side's count, in a blocked or tied hand; null otherwise. */
    counts: { side: number; pips: number }[] | null;
    /** Why a blocked hand was won: «Az xal qalan tərəf udur: 24 < 36». */
    lower: SheetWords | null;
    lines: SheetLine[];
    /** Who opens the next hand and why; null once the game is over (ADR 038 decision 66). */
    next: SheetWords | null;
    /** When the next hand is dealt; null once the game is over. */
    until: number | null;
};

/** The lines that say an amount burnt: in words, never a colour alone. */
const BURNT: ReadonlySet<RoomLedgerLine> = new Set([
    'burnt_rival',
    'burnt_third',
    'bank_burnt',
]);

/**
 * The sheet of the hand that ended, or null outside a pause and an end
 * with its last hand's sheet. `nameOf` names a seat — a player, or the
 * computer — as the page names it.
 */
export function sheetView(
    domino: Pick<RoomDomino, 'sheet'>,
    nameOf: (seat: number) => string,
): SheetView | null {
    const sheet = domino.sheet;

    if (sheet === null) {
        return null;
    }

    const how: SheetWords =
        sheet.ending === 'finished'
            ? {
                  key: 'rooms.domino.sheet.finished',
                  params: { name: nameOf(sheet.by) },
              }
            : { key: `rooms.domino.sheet.${sheet.ending}`, params: {} };
    const counted = sheet.ending === 'blocked' || sheet.ending === 'tied';
    const winner =
        sheet.winner === null
            ? undefined
            : sheet.counts.find((count) => count.side === sheet.winner);
    const others = sheet.counts.filter((count) => count.side !== sheet.winner);
    const lower =
        sheet.ending === 'blocked' && winner !== undefined && others.length > 0
            ? {
                  key: 'rooms.domino.sheet.lower',
                  params: {
                      low: winner.pips,
                      high: Math.min(...others.map((count) => count.pips)),
                  },
              }
            : null;

    return {
        hand: sheet.hand,
        how,
        left: sheet.left.map((seat) => ({
            ...seat,
            loneBlank: seat.tiles.length === 1 && seat.tiles[0] === '0-0',
        })),
        counts: counted ? sheet.counts : null,
        lower,
        lines: sheet.lines.map((line) => ({
            key: `rooms.domino.sheet.line.${line.line}`,
            params: { count: line.points },
            side: line.side,
            line: line.line,
            burnt: BURNT.has(line.line),
        })),
        next:
            sheet.next === null
                ? null
                : sheet.next.seat === null
                  ? { key: 'rooms.domino.sheet.next_double', params: {} }
                  : {
                        key: 'rooms.domino.sheet.next',
                        params: {
                            name: nameOf(sheet.next.seat),
                            why: `rooms.domino.sheet.why.${sheet.next.why}`,
                        },
                    },
        until: sheet.until_ms,
    };
}

/** How far a side's balance stands from the target: «101-ə qədər: :count», never below 0. */
export function toTarget(balance: number, target: number): number {
    return Math.max(0, target - balance);
}
