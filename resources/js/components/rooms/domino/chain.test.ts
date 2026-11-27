import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomDomino } from '@/rooms/contract';
import {
    CHAIN_GAP,
    chainBounds,
    halfCentre,
    layoutChain,
    PHONE_CHAIN,
    SCREEN_CHAIN,
    upright,
} from './chain';
import type { ChainBox, ChainLayout } from './chain';

/*
 * The line where it meets the table's edge (docs/frontend.md rule 15
 * "Domino" → "The table", ADR 038 decision 87): «ilan», a snake from the
 * box's top left, laid out by one pure function of the line and the box —
 * the same on a phone and on the screen —, every tile shrunk together
 * when the line would not fit, the two ends' targets beyond its ends.
 */

/** A phone's table at 375 × 667: 351 × 288 px for the line (rule 15 "A game on a seat's phone"). */
const PHONE: ChainBox = { width: 351, height: 288, ...PHONE_CHAIN };

/** The screen's table at 1920 × 1080. */
const SCREEN: ChainBox = { width: 1320, height: 540, ...SCREEN_CHAIN };

/** A line from `left:right` pairs, each tile named the larger number first. */
function line(spec: string): RoomDomino['line'] {
    return spec.split(' ').map((pair) => {
        const [left, right] = pair.split(':').map(Number);

        return {
            tile: `${Math.max(left, right)}-${Math.min(left, right)}`,
            left,
            right,
        };
    });
}

/** All 28 tiles in one line, seven doubles among them. */
const ALL = line(
    '6:6 6:5 5:5 5:4 4:6 6:3 3:5 5:2 2:6 6:1 1:5 5:0 0:4 4:4 4:3 3:3 3:2 2:4 4:1 1:3 3:0 0:2 2:2 2:1 1:1 1:0 0:0 0:6',
);
const SEVEN = ALL.slice(0, 7);
const ONE = line('6:5');

type Rect = [number, number, number, number];

function rect(item: { x: number; y: number; angle: number }): Rect {
    const [hx, hy] = upright(item.angle) ? [0.5, 1] : [1, 0.5];

    return [item.x - hx, item.y - hy, item.x + hx, item.y + hy];
}

/** How far apart two rectangles are (0 where they touch or overlap). */
function apart(a: Rect, b: Rect): number {
    const dx = Math.max(0, a[0] - b[2], b[0] - a[2]);
    const dy = Math.max(0, a[1] - b[3], b[1] - a[3]);

    return Math.hypot(dx, dy);
}

function overlap(a: Rect, b: Rect): boolean {
    const ox = Math.min(a[2], b[2]) - Math.max(a[0], b[0]);
    const oy = Math.min(a[3], b[3]) - Math.max(a[1], b[1]);

    return ox > 0.01 && oy > 0.01;
}

const distance = (p: [number, number], q: [number, number]) =>
    Math.hypot(p[0] - q[0], p[1] - q[1]);

/** What every layout of `input` in `box` holds. */
function holds(layout: ChainLayout, input: RoomDomino['line'], box: ChainBox) {
    const eps = 1e-6;
    const [vx, vy, vw, vh] = layout.viewBox;

    // The line as the state has it, tile by tile, each number where it lies.
    expect(
        layout.tiles.map((tile) => [tile.tile, tile.near, tile.far]),
    ).toEqual(input.map((placed) => [placed.tile, placed.left, placed.right]));
    // One unit for every tile, and the box exactly.
    expect(vw * layout.unit).toBeCloseTo(box.width, 6);
    expect(vh * layout.unit).toBeCloseTo(box.height, 6);
    expect(layout.unit).toBeLessThanOrEqual(box.unitMax);

    if (input.length === 0) {
        expect(layout.targets).toEqual([]);

        return;
    }

    expect(layout.targets.map((target) => target.end)).toEqual([
        'left',
        'right',
    ]);

    const rects = [...layout.tiles, ...layout.targets].map(rect);

    // Inside the box, and no two on each other.
    for (const r of rects) {
        expect(r[0]).toBeGreaterThanOrEqual(vx - eps);
        expect(r[1]).toBeGreaterThanOrEqual(vy - eps);
        expect(r[2]).toBeLessThanOrEqual(vx + vw + eps);
        expect(r[3]).toBeLessThanOrEqual(vy + vh + eps);
    }

    rects.forEach((a, i) =>
        rects.slice(i + 1).forEach((b) => expect(overlap(a, b)).toBe(false)),
    );

    // Each tile meets the next one gap apart, with the numbers that match facing each other.
    layout.tiles.slice(1).forEach((tile, i) => {
        const before = layout.tiles[i];

        expect(apart(rect(before), rect(tile))).toBeLessThanOrEqual(
            CHAIN_GAP + eps,
        );

        if (before.near !== before.far && tile.near !== tile.far) {
            const meet = distance(
                halfCentre(before, 'far'),
                halfCentre(tile, 'near'),
            );

            for (const [a, b] of [
                ['near', 'near'],
                ['near', 'far'],
                ['far', 'far'],
            ] as const) {
                expect(meet).toBeLessThan(
                    distance(halfCentre(before, a), halfCentre(tile, b)),
                );
            }
        }
    });

    // The targets lie beyond the two ends — the left one two gaps off, as Y3's page laid it.
    const [left, right] = layout.targets;

    expect(apart(rect(left), rect(layout.tiles[0]))).toBeLessThanOrEqual(
        2 * CHAIN_GAP + eps,
    );
    expect(
        apart(rect(right), rect(layout.tiles[layout.tiles.length - 1])),
    ).toBeLessThanOrEqual(CHAIN_GAP + eps);
}

/** The rows of a layout: its tiles' distinct heights, top to bottom. */
function rows(layout: ChainLayout): number[] {
    return [...new Set(layout.tiles.map((tile) => tile.y.toFixed(4)))]
        .map(Number)
        .sort((a, b) => a - b);
}

const fixtures = import.meta.glob<unknown>(
    '../../../../../tests/Fixtures/rooms/state.domino.*.json',
    { eager: true, import: 'default' },
);

describe('the line of a domino game, laid as a snake', () => {
    it('lays one tile at the largest unit, its targets either side', () => {
        for (const box of [PHONE, SCREEN]) {
            const layout = layoutChain(ONE, box);

            holds(layout, ONE, box);
            expect(layout.unit).toBe(box.unitMax);
            expect(rows(layout)).toHaveLength(1);
            // A tile along the row, its larger number at the left (ADR 038 decision 62).
            expect(upright(layout.tiles[0].angle)).toBe(false);
            expect(halfCentre(layout.tiles[0], 'near')[0]).toBeLessThan(
                halfCentre(layout.tiles[0], 'far')[0],
            );
        }
    });

    it('lays seven in one row on the screen, each double across it', () => {
        const layout = layoutChain(SEVEN, SCREEN);

        holds(layout, SEVEN, SCREEN);
        expect(layout.unit).toBe(SCREEN.unitMax);
        expect(rows(layout)).toHaveLength(1);
        expect(layout.tiles.map((tile) => upright(tile.angle))).toEqual(
            SEVEN.map((placed) => placed.left === placed.right),
        );
    });

    it('turns seven at a phone table’s edge: a corner tile, the next row back the other way', () => {
        const layout = layoutChain(SEVEN, PHONE);

        holds(layout, SEVEN, PHONE);
        expect(layout.unit).toBe(PHONE.unitMax);
        expect(rows(layout).length).toBeGreaterThan(1);

        const corners = layout.tiles.filter(
            (tile, i) =>
                upright(tile.angle) && SEVEN[i].left !== SEVEN[i].right,
        );

        expect(corners.length).toBeGreaterThan(0);

        // The first row runs left to right, the row under it right to left.
        const top = rows(layout)[0];
        const first = layout.tiles.filter(
            (tile) => tile.y.toFixed(4) === top.toFixed(4),
        );

        const xs = first.map((tile) => tile.x);

        expect(xs).toEqual(xs.toSorted((a, b) => a - b));
    });

    it('lays all 28 tiles in five rows of a phone table, and on the screen', () => {
        const phone = layoutChain(ALL, PHONE);
        const screen = layoutChain(ALL, SCREEN);

        holds(phone, ALL, PHONE);
        holds(screen, ALL, SCREEN);
        expect(phone.unit).toBe(29.5);
        expect(rows(phone).length).toBeGreaterThan(rows(screen).length);
        expect(screen.unit).toBeGreaterThanOrEqual(SCREEN.unitMin);
    });

    it('shrinks every tile together where even the smallest unit does not fit', () => {
        // A box far smaller than any table: the snake holds 28 tiles in a phone's even at 120px tall.
        const box = { ...PHONE, width: 100, height: 100 };
        const layout = layoutChain(ALL, box);

        holds(layout, ALL, box);
        expect(layout.unit).toBeLessThan(PHONE.unitMin);

        // Shrunk to the box: one side of the snake fills it exactly.
        const b = chainBounds([...layout.tiles, ...layout.targets]);

        expect(
            Math.max(
                ((b.x1 - b.x0) * layout.unit) / box.width,
                ((b.y1 - b.y0) * layout.unit) / box.height,
            ),
        ).toBeCloseTo(1, 6);
    });

    it('never lays a double across right after a corner', () => {
        for (const box of [PHONE, SCREEN, { ...PHONE, width: 200 }]) {
            const layout = layoutChain(ALL, box);

            layout.tiles.forEach((tile, i) => {
                const before = layout.tiles[i - 1];

                if (
                    before !== undefined &&
                    ALL[i].left === ALL[i].right &&
                    ALL[i - 1].left !== ALL[i - 1].right &&
                    upright(before.angle)
                ) {
                    // After a corner the row runs on: the double lies along it.
                    expect(upright(tile.angle)).toBe(false);
                }
            });
        }
    });

    it('gives the same layout for the same line and box, and nothing for an empty line', () => {
        expect(layoutChain(ALL, PHONE)).toEqual(layoutChain(ALL, PHONE));

        const empty = layoutChain([], SCREEN);

        holds(empty, [], SCREEN);
        expect(empty.tiles).toEqual([]);
        expect(empty.unit).toBe(SCREEN.unitMax);
    });

    it('lays every line of the fixtures on a phone and on the screen', () => {
        const names = Object.keys(fixtures);

        expect(names).toHaveLength(14);

        for (const name of names) {
            const domino = RoomState.parse(fixtures[name]).domino;
            const placed = domino?.line ?? [];

            for (const box of [PHONE, SCREEN]) {
                holds(layoutChain(placed, box), placed, box);
            }
        }
    });
});
