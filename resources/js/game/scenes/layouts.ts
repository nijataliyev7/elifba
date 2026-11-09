/**
 * Where each build scene puts things on the 1920×1080 board
 * (docs/architecture.md §2): the slots in one band, the tiles in the band
 * below. build_word also has its picture at the top, so its bands are
 * lower and narrower. Kept apart from the scenes, which need Phaser, so
 * Vitest checks every item and slot style fits them
 * (`styles/registry.test.ts`).
 */
import type { Mechanic } from '../contract';
import type { Band } from '../styles/types';

export type BuildLayout = { slots: Band; tiles: Band };

export type BuildMechanic = Exclude<Mechanic, 'find'>;

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

/** build_word's picture frame, at the top of the board above its slots. */
export const PICTURE_FRAME = { x: 960, y: 192, width: 400, height: 364 };
