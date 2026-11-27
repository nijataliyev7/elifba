import type { SeatPlace } from '@/components/rooms/domino/places';
import { handRows } from '@/components/rooms/domino/places';
import { PLAY_MS } from '@/components/rooms/domino/turn';
import type { RoomDomino } from '@/rooms/contract';

/*
 * Where and when a domino page draws its parts (docs/frontend.md rule 15
 * "Domino" → "A game on a seat's phone", "The table", "The hand",
 * "Motion"; ADR 038 "As built (Y7)"), the part that is pure: the phone's
 * heights, what the table draws of an act the page saw arrive, when a
 * hand deals, the flight's keyframes, where a step's mark stands on a
 * chip and what it is keyed by, a hand's places in one list, and how the
 * sheet is laid out. It decides nothing of the game: the state says it.
 */

/**
 * A seat's phone at 375 × 667, top to bottom, in px: the game frame's top
 * bar (56 and its 1px border), the strip with its gap and its row of the
 * accounts' words, the line under the table and one row of the hand —
 * the table takes the rest and is never under `table`.
 */
export const PHONE_PX = {
    bar: 57,
    strip: 120,
    line: 44,
    hand: 108,
    table: 200,
} as const;

/** The table's height on a seat's phone of `height` px with one row of tiles (the rest of the column). */
export function phoneTable(height: number): number {
    return (
        height - PHONE_PX.bar - PHONE_PX.strip - PHONE_PX.line - PHONE_PX.hand
    );
}

/** A play the page saw arrive, as the table draws it: its seat, its tile (null: nothing flies) and its moment. */
export type TableArrival = { seat: number; tile: string | null; key: string };

/**
 * What the table draws of the act the page saw arrive (ADR 038 decision
 * 47): the viewer's own play already stands at its end and does not fly,
 * but what followed it by itself — a seat drawing, a seat passing — is
 * drawn on the viewer's phone too, a step at a time.
 */
export function tableArrival(
    arrival: TableArrival | null,
    own: boolean,
): TableArrival | null {
    return arrival !== null && own ? { ...arrival, tile: null } : arrival;
}

/**
 * Whether the hand deals its tiles in (ADR 025, ADR 038 decision 52): in
 * the one moment of a hand the page saw open — before its first act —
 * and never later in the hand, so a tile that comes later is drawn as
 * what it is (a draw at its step) or still.
 */
export function dealtNow(
    sawOpen: boolean,
    domino: Pick<RoomDomino, 'last' | 'phase'>,
): boolean {
    return sawOpen && domino.phase === 'play' && domino.last === null;
}

/**
 * Whether a play's flight is drawn: while it flies, and never once it
 * landed — the line's own tile shows then, so a play is never drawn
 * twice, whatever the table does after it.
 */
export function flies(key: string | null, landed: string | null): boolean {
    return key !== null && key !== landed;
}

/**
 * Whether the flight may start: once the table was measured at the size
 * it now has, so it flies to the place the line is drawn at (a hand that
 * gains a row in the same moment changes the table's height).
 */
export function flyReady(
    measured: { width: number; height: number },
    box: { width: number; height: number },
): boolean {
    return measured.width === box.width && measured.height === box.height;
}

/**
 * A play's flight («oturma», ADR 038 decision 89) as two animations of one
 * timing — the tile and its shadow under it — in transform and opacity
 * only (docs/frontend.md rule 15 "Domino" → "Motion"): the tile lifts at
 * its seat (90 ms, its shadow falling away and darkening), flies to its
 * place turning to lie as the line has it (280 ms) and settles (140 ms).
 */
export function flightFrames(
    from: [number, number],
    to: [number, number],
    angle: number,
    unit: number,
): { tile: Keyframe[]; shadow: Keyframe[]; duration: number } {
    const duration = PLAY_MS.lift + PLAY_MS.fly + PLAY_MS.settle;
    const at = (
        point: [number, number],
        turn: number,
        scale: number,
        lift = 0,
    ) =>
        `translate(${point[0] - unit / 2}px, ${point[1] - unit - lift}px) rotate(${turn}deg) scale(${scale})`;
    const lifted = PLAY_MS.lift / duration;
    const flown = (PLAY_MS.lift + PLAY_MS.fly) / duration;
    const settling = (PLAY_MS.lift + PLAY_MS.fly + 70) / duration;

    return {
        duration,
        tile: [
            { transform: at(from, 0, 0.9), opacity: 1, offset: 0 },
            { transform: at(from, 0, 1.08, 10), opacity: 1, offset: lifted },
            { transform: at(to, angle, 1.06, 4), opacity: 1, offset: flown },
            { transform: at(to, angle, 0.97), opacity: 1, offset: settling },
            { transform: at(to, angle, 1), opacity: 1, offset: 1 },
        ],
        shadow: [
            { transform: 'translate(0, 1px)', opacity: 0.25, offset: 0 },
            { transform: 'translate(0, 10px)', opacity: 0.35, offset: lifted },
            { transform: 'translate(0, 8px)', opacity: 0.3, offset: flown },
            { transform: 'translate(0, 1px)', opacity: 0.25, offset: settling },
            { transform: 'translate(0, 1px)', opacity: 0.25, offset: 1 },
        ],
    };
}

/**
 * How many lines a seat's chip takes (docs/frontend.md rule 15 "Domino" →
 * "The table"): on a phone two — the name over the tiles and the seconds —
 * so three chips across 375px keep their names; on the screen one, but a
 * side seat's, which wraps its name over its tiles to stay narrow.
 */
export function chipLines(big: boolean, upright: boolean): 1 | 2 {
    return !big || upright ? 2 : 1;
}

/** A phone's chips: 36px tall, 6px from the table's edge and 4px from the line's box (46px in all). */
export const PHONE_CHIP_PX = { height: 36, edge: 6, gap: 4 } as const;

/**
 * Whether a step's mark («Bazar :count×», «PAS») stands under its seat's
 * chip rather than over it, so the table's edge never cuts it: on a phone
 * every chip but the bottom row's stands along the table's top; on the
 * screen the chip at the top edge.
 */
export function markBelow(
    big: boolean,
    place: SeatPlace,
    bottomRow: boolean,
): boolean {
    return big ? place === 'top' : !bottomRow;
}

/**
 * A step's key: its moment and the step, so the same step of the next
 * moment — a seat passing again — is a new mark whose window starts again.
 */
export function stepKey(
    moment: string,
    step: { kind: string; seat: number; at: number },
): string {
    return `${moment}:${step.kind}:${step.seat}:${step.at}`;
}

/**
 * A hand's tiles in one list (docs/frontend.md rule 15 "Domino" → "The
 * hand"), each with its grid row and its first column in half-tile
 * columns: one row up to seven; from eight the first half at the bottom
 * and the rest above it, centred over it — so a tile that moves between
 * the rows keeps its place in the list and is never drawn anew.
 */
export function handPlaces(tiles: readonly string[]): {
    places: { tile: string; row: 1 | 2; column: number }[];
    across: number;
    rows: 1 | 2;
} {
    const { bottom, top, across } = handRows(tiles);
    const offset = across - top.length;

    return {
        places: [
            ...bottom.map((tile, index) => ({
                tile,
                row: (top.length > 0 ? 2 : 1) as 1 | 2,
                column: 2 * index + 1,
            })),
            ...top.map((tile, index) => ({
                tile,
                row: 1 as const,
                column: offset + 2 * index + 1,
            })),
        ],
        across,
        rows: top.length > 0 ? 2 : 1,
    };
}

/** How many of a hand's tiles stand past the edge when `shown` tiles across are seen whole. */
export function offEdge(tiles: readonly string[], shown: number): number {
    return handPlaces(tiles).places.filter(
        (place) => (place.column - 1) / 2 + 1 > shown,
    ).length;
}

/**
 * How a hand's sheet is laid out: on the screen's panel in two columns —
 * the hand's tiles and counts beside the account, the balances, who opens
 * next and the bar — so it fits the table under it; on a phone and under
 * the screen's final in one, each seat's tiles in a row of its own.
 */
export function sheetColumns(big: boolean, still: boolean): 1 | 2 {
    return big && !still ? 2 : 1;
}
