import type { RoomDomino, RoomDominoEnd } from '@/rooms/contract';

/*
 * The line of a domino game where it meets the table's edge (docs/frontend.md
 * rule 15 "Domino" → "The table", ADR 038 decisions 52 and 87): «ilan», the
 * owner's pick on Y3's variant page — a snake from the box's top left. The
 * part that is pure, one function of the line and the box, the same on a
 * phone and on the screen: where each tile lies and how it is turned, and
 * where the two ends' targets are. Ported from the variant page's own
 * `layoutChain()` (mode `ilan`). It judges nothing: the line, each tile's
 * two numbers as it lies and which ends exist are the state's.
 *
 * Units: a tile is 1 wide and 2 long, and is drawn as the canonical upright
 * tile — `near` above, `far` below — turned by `angle` degrees (clockwise,
 * as SVG turns) about its centre `x`, `y`. `near` is the number nearer the
 * line's left end. The page draws the layout in a `viewBox` of these units
 * at `unit` px a unit.
 */

/** A placed tile of the line: its name, its two numbers in the line's order, its centre and its turn. */
export type ChainTile = {
    tile: string;
    near: number;
    far: number;
    x: number;
    y: number;
    angle: number;
};

/** Where a tile played at an end would lie: the target a tile that fits both ends is placed on (ADR 038 decision 88). */
export type ChainTarget = {
    end: RoomDominoEnd;
    x: number;
    y: number;
    angle: number;
};

/** The box the line is laid out in, in px, and the size a unit may take in it. */
export type ChainBox = {
    width: number;
    height: number;
    /** The largest a unit — a tile's width — is drawn, in px. */
    unitMax: number;
    /** The smallest unit tried before every tile is shrunk together to fit. */
    unitMin: number;
};

export type ChainLayout = {
    /** From the line's left end to its right, as `line` has them. */
    tiles: ChainTile[];
    /** The left end's, then the right end's; none before the hand's first tile. */
    targets: ChainTarget[];
    /** Px a unit, the same for every tile. */
    unit: number;
    /** `[x, y, width, height]` in units: the box, the snake centred in it. */
    viewBox: [number, number, number, number];
};

/** A phone's table: the line at most 30px a unit, shrunk below 9 only when it cannot fit (ADR 038 decision 87). */
export const PHONE_CHAIN = { unitMax: 30, unitMin: 9 } as const;

/** The screen's table: from 64px a unit down to 24. */
export const SCREEN_CHAIN = { unitMax: 64, unitMin: 24 } as const;

/** The gap between two tiles of the line, in units. */
export const CHAIN_GAP = 0.06;

/** How far below `unitMax` each try steps, in px. */
const UNIT_STEP = 0.5;

/** The room kept free at either side of the box, in units. */
const EDGE = 0.15;

type Point = [number, number];

const add = (p: Point, q: Point): Point => [p[0] + q[0], p[1] + q[1]];
const mul = (p: Point, k: number): Point => [p[0] * k, p[1] * k];

/** The turn of an upright tile whose `near` half points against `d`. */
const angleOf = (d: Point): number => (Math.atan2(-d[0], d[1]) * 180) / Math.PI;

/** Whether a tile turned by `angle` stands upright (its long side vertical). */
export function upright(angle: number): boolean {
    return Math.round(Math.abs(angle) / 90) % 2 === 0;
}

/** A tile's half-size along x and y as it is turned. */
function halves(angle: number): Point {
    return upright(angle) ? [0.5, 1] : [1, 0.5];
}

type Step = {
    tile: string | null;
    end: RoomDominoEnd | null;
    near: number;
    far: number;
    double: boolean;
};

type Laid = (ChainTile & { end: null }) | (ChainTarget & { tile: null });

/**
 * Lays `steps` along a row from `start` in direction `d`, turning in `s`
 * where a tile would leave room for no corner before the box's half-width
 * `half`: the corner tile turned along `s`, the next row back the other way.
 * A double lies across the line, but at a corner and right after one.
 */
function snake(steps: Step[], start: Point, half: number, out: Laid[]): void {
    let p = start;
    let d: Point = [1, 0];
    const s: Point = [0, 1];
    let afterCorner = false;
    const inside = (point: Point) => Math.abs(point[0]) <= half + 1e-6;

    for (const step of steps) {
        const across = step.double && !afterCorner;
        const length = across ? 1 : 2;
        const from = afterCorner ? p : add(p, mul(d, CHAIN_GAP));
        const far = add(from, mul(d, length));
        let centre: Point;
        let angle: number;

        if (inside(add(far, mul(d, 1 + CHAIN_GAP)))) {
            centre = add(from, mul(d, length / 2));
            angle = across ? angleOf(d) + 90 : angleOf(d);
            p = far;
            afterCorner = false;
        } else {
            const q = add(p, mul(d, CHAIN_GAP));
            centre = add(add(q, mul(d, 0.5)), mul(s, 0.5));
            angle = angleOf(s);
            p = add(add(q, d), mul(s, 2 + CHAIN_GAP));
            d = mul(d, -1);
            afterCorner = true;
        }

        out.push(
            step.tile === null
                ? {
                      tile: null,
                      end: step.end ?? 'right',
                      x: centre[0],
                      y: centre[1],
                      angle,
                  }
                : {
                      tile: step.tile,
                      end: null,
                      near: step.near,
                      far: step.far,
                      x: centre[0],
                      y: centre[1],
                      angle,
                  },
        );
    }
}

/** The line and its two targets laid as «ilan» for a box `half` units either side of its middle. */
function arrange(line: RoomDomino['line'], half: number): Laid[] {
    const out: Laid[] = [];

    if (line.length === 0) {
        return out;
    }

    const steps: Step[] = [
        ...line.map((placed) => ({
            tile: placed.tile,
            end: null,
            near: placed.left,
            far: placed.right,
            double: placed.left === placed.right,
        })),
        { tile: null, end: 'right', near: -1, far: -1, double: false },
    ];
    // The row starts after the left end's target, which lies before the first tile.
    const start: Point = [-half + 2 + CHAIN_GAP, 0];

    snake(steps, start, half, out);

    const left = add(start, [-(1 + CHAIN_GAP), 0]);

    out.push({
        tile: null,
        end: 'left',
        x: left[0],
        y: left[1],
        angle: angleOf([-1, 0]),
    });

    return out;
}

/** The box round every laid tile and target, in units. */
export function chainBounds(
    items: readonly { x: number; y: number; angle: number }[],
): {
    x0: number;
    y0: number;
    x1: number;
    y1: number;
} {
    let x0 = Infinity;
    let y0 = Infinity;
    let x1 = -Infinity;
    let y1 = -Infinity;

    for (const item of items) {
        const [hx, hy] = halves(item.angle);

        x0 = Math.min(x0, item.x - hx);
        x1 = Math.max(x1, item.x + hx);
        y0 = Math.min(y0, item.y - hy);
        y1 = Math.max(y1, item.y + hy);
    }

    return { x0, y0, x1, y1 };
}

function finish(items: Laid[], unit: number, box: ChainBox): ChainLayout {
    const width = box.width / unit;
    const height = box.height / unit;
    // An empty line stands nowhere: the box centred on the origin.
    const b =
        items.length === 0
            ? { x0: 0, y0: 0, x1: 0, y1: 0 }
            : chainBounds(items);
    const tiles: ChainTile[] = [];
    const targets: ChainTarget[] = [];

    for (const item of items) {
        if (item.tile === null) {
            targets.push({
                end: item.end,
                x: item.x,
                y: item.y,
                angle: item.angle,
            });
        } else {
            tiles.push({
                tile: item.tile,
                near: item.near,
                far: item.far,
                x: item.x,
                y: item.y,
                angle: item.angle,
            });
        }
    }

    targets.sort((a, b) => (a.end === b.end ? 0 : a.end === 'left' ? -1 : 1));

    return {
        tiles,
        targets,
        unit,
        viewBox: [
            (b.x0 + b.x1) / 2 - width / 2,
            (b.y0 + b.y1) / 2 - height / 2,
            width,
            height,
        ],
    };
}

/**
 * The line laid out in `box` (ADR 038 decision 87): the largest unit from
 * `unitMax` down to `unitMin`, in steps of half a pixel, at which the line
 * and both targets fit; when none does, the smallest one's layout shrunk
 * until it fits — every tile together. A line with no tile lays out
 * nothing. The same line and box give the same layout on every device.
 */
export function layoutChain(
    line: RoomDomino['line'],
    box: ChainBox,
): ChainLayout {
    if (line.length === 0) {
        return finish([], box.unitMax, box);
    }

    let last: { items: Laid[]; unit: number } | null = null;

    for (let unit = box.unitMax; unit >= box.unitMin; unit -= UNIT_STEP) {
        const items = arrange(line, box.width / unit / 2 - EDGE);
        const b = chainBounds(items);

        last = { items, unit };

        if (
            (b.x1 - b.x0) * unit <= box.width &&
            (b.y1 - b.y0) * unit <= box.height
        ) {
            return finish(items, unit, box);
        }
    }

    if (last === null) {
        return finish([], box.unitMin, box);
    }

    const b = chainBounds(last.items);

    return finish(
        last.items,
        Math.min(
            last.unit,
            box.width / (b.x1 - b.x0),
            box.height / (b.y1 - b.y0),
        ),
        box,
    );
}

/**
 * The centre of a placed tile's `near` or `far` half, in units: where
 * two tiles that meet in the line touch.
 */
export function halfCentre(
    item: { x: number; y: number; angle: number },
    half: 'near' | 'far',
): [number, number] {
    const a = (item.angle * Math.PI) / 180;
    // The upright tile's near half is 0.5 above its centre, its far half 0.5 below.
    const dy = half === 'near' ? -0.5 : 0.5;

    return [item.x - dy * Math.sin(a), item.y + dy * Math.cos(a)];
}
