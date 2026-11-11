/**
 * Where each build scene puts things on the 1920×1080 board
 * (docs/architecture.md §2): the slots in one band, the tiles in the band
 * below. build_word also has its picture at the top, so its bands are
 * lower and narrower. The count scene (R5) has a question band of
 * pictures over its items. Kept apart from the scenes, which need Phaser,
 * so Vitest checks every item and slot style fits them
 * (`styles/registry.test.ts`).
 */
import type { Mechanic } from '../contract';
import type { Band } from '../styles/types';

export type BuildLayout = { slots: Band; tiles: Band };

/**
 * The mechanics whose level's items are hit in any order (`Mechanic::builds()`
 * is false): no slots, drawn by the find scene (`count` by its own, which
 * adds the question band).
 */
export type HitMechanic = Extract<Mechanic, 'find' | 'pop' | 'count'>;

export type BuildMechanic = Exclude<Mechanic, HitMechanic>;

export const BUILD_LAYOUTS: Record<BuildMechanic, BuildLayout> = {
    build_syllable: {
        slots: { top: 150, bottom: 520 },
        tiles: { top: 600, bottom: 1066 },
    },
    build_word: {
        slots: { top: 395, bottom: 665 },
        tiles: { top: 700, bottom: 1066 },
    },
    build_sentence: {
        slots: { top: 150, bottom: 520 },
        tiles: { top: 600, bottom: 1066 },
    },
};

/** Whether a round of this mechanic fills slots (`Mechanic::builds()`). */
export function builds(mechanic: Mechanic): mechanic is BuildMechanic {
    return Object.hasOwn(BUILD_LAYOUTS, mechanic);
}

/** build_word's picture frame, at the top of the board above its slots. */
export const PICTURE_FRAME = { x: 960, y: 192, width: 400, height: 364 };

/**
 * The count scene (R5, ADR 012): the question band holds the counted
 * picture N times, the items (numbers) stand below in the pack's item style,
 * in the band a build round's tiles take.
 */
export const COUNT_LAYOUT = {
    question: { top: 24, bottom: 572 },
    items: { top: 600, bottom: 1066 },
} as const satisfies Record<string, Band>;

/** At most this many pictures in a row: a ten-frame, 7 is a full row and two. */
export const COUNT_ROW = 5;

/** The most pictures a count round draws (`prompt.count`, 1–10). */
export const COUNT_MAX = 10;

export type CountPictures = {
    /** The side of each picture's square. */
    size: number;
    spots: { x: number; y: number }[];
};

/**
 * Where the `count` pictures stand in `band`: rows of five. Up to five
 * stand in one row in the middle; six to ten fill a first row of five and
 * start a second one under it from the left, as a ten-frame does, so the
 * child sees "five and two". The size never changes with N, so more apples
 * never look smaller.
 */
export function countPictures(
    count: number,
    band: Band = COUNT_LAYOUT.question,
): CountPictures {
    const gap = 36;
    const rows = 2;
    const size = Math.min(
        220,
        (band.bottom - band.top - gap * (rows + 1)) / rows,
    );
    const step = size + gap;
    const middle = (band.top + band.bottom) / 2;
    const n = Math.max(0, Math.min(count, COUNT_MAX));

    if (n <= COUNT_ROW) {
        return {
            size,
            spots: Array.from({ length: n }, (_, index) => ({
                x: 960 + (index - (n - 1) / 2) * step,
                y: middle,
            })),
        };
    }

    return {
        size,
        spots: Array.from({ length: n }, (_, index) => ({
            x: 960 + ((index % COUNT_ROW) - (COUNT_ROW - 1) / 2) * step,
            y: middle + (Math.floor(index / COUNT_ROW) - 0.5) * step,
        })),
    };
}
