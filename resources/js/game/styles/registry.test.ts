import { describe, expect, it, vi } from 'vite-plus/test';
import { Effect } from '../contract';
import { BUILD_LAYOUTS } from '../scenes/layouts';
import {
    EFFECTS,
    ITEM_STYLES,
    SLOT_STYLES,
    effect,
    itemStyle,
    slotStyle,
} from './registry';
import { frameGeometry } from './slot-frame';
import { trainGeometry } from './slot-wagon';

// The styles touch Phaser only while they draw; this test never draws.
vi.mock('phaser', () => ({ default: {} }));

/*
 * Every item style, slot style and effect the server lists
 * (`Play\Support\Playable`, pinned in tests/Fixtures/playable.json by
 * tests/Unit/Play/PlayableTest.php) has its code in the registry, and the
 * registry has nothing the server does not list.
 */
const fixtures = import.meta.glob<{
    item_styles: string[];
    slot_styles: string[];
    effects: string[];
}>('../../../../tests/Fixtures/playable.json', {
    eager: true,
    import: 'default',
});
const playable = Object.values(fixtures)[0];

describe('the styles registry', () => {
    it('reads the server lists', () => {
        expect(playable).toBeDefined();
        expect(playable.item_styles.length).toBeGreaterThan(0);
    });

    it('has every item style the server lists, and only those', () => {
        expect(Object.keys(ITEM_STYLES).sort()).toEqual(
            [...playable.item_styles].sort(),
        );

        for (const key of playable.item_styles) {
            const style = itemStyle(key);

            expect(style, key).not.toBeNull();
            expect(typeof style?.draw, key).toBe('function');
            expect(typeof style?.create, key).toBe('function');
        }
    });

    it('has every effect the server lists and the contract allows', () => {
        expect(Object.keys(EFFECTS).sort()).toEqual(
            [...playable.effects].sort(),
        );
        expect([...Effect.options].sort()).toEqual(
            [...playable.effects].sort(),
        );

        for (const key of Effect.options) {
            expect(typeof effect(key).play, key).toBe('function');
        }
    });

    it('has every slot style the server lists, and only those', () => {
        expect(Object.keys(SLOT_STYLES).sort()).toEqual(
            [...playable.slot_styles].sort(),
        );

        for (const key of playable.slot_styles) {
            expect(typeof slotStyle(key)?.create, key).toBe('function');
        }
    });

    it('refuses a style it does not have', () => {
        expect(itemStyle('rocket')).toBeNull();
        expect(itemStyle('toString')).toBeNull();
        expect(slotStyle('rocket')).toBeNull();
        expect(slotStyle('hasOwnProperty')).toBeNull();
    });

    it.each(Object.entries(ITEM_STYLES))(
        '%s lays out items on the board without overlap',
        (_, style) => {
            for (const count of [1, 2, 4, 6]) {
                const { width, spots } = style.layout(count);

                expect(spots).toHaveLength(count);
                expect(width).toBeGreaterThan(100);

                for (const [index, spot] of spots.entries()) {
                    expect(spot.x - width / 2).toBeGreaterThanOrEqual(0);
                    expect(spot.x + width / 2).toBeLessThanOrEqual(1920);
                    expect(spot.y).toBeGreaterThan(0);
                    expect(spot.y).toBeLessThan(1080);

                    if (index > 0) {
                        expect(spot.x - spots[index - 1].x).toBeGreaterThan(
                            width,
                        );
                    }
                }
            }
        },
    );

    // A build round: up to 4 slots (a CVCC syllable, a 4-syllable word,
    // a 4-word sentence) and one decoy, so up to 5 tiles.
    const builds = Object.entries(BUILD_LAYOUTS);

    it.each(Object.entries(ITEM_STYLES))(
        '%s lays out a build round’s tiles inside their band without overlap',
        (_, style) => {
            for (const [mechanic, { tiles }] of builds) {
                for (const count of [2, 3, 4, 5]) {
                    const { width, height, spots } = style.layout(count, tiles);

                    expect(width, mechanic).toBeGreaterThan(150);

                    for (const [index, spot] of spots.entries()) {
                        expect(spot.x - width / 2).toBeGreaterThanOrEqual(0);
                        expect(spot.x + width / 2).toBeLessThanOrEqual(1920);
                        expect(spot.y - height / 2).toBeGreaterThanOrEqual(
                            tiles.top,
                        );
                        expect(spot.y + height / 2).toBeLessThanOrEqual(
                            tiles.bottom,
                        );

                        if (index > 0) {
                            expect(spot.x - spots[index - 1].x).toBeGreaterThan(
                                width,
                            );
                        }
                    }
                }
            }
        },
    );

    it.each(Object.entries(ITEM_STYLES))(
        'frames for %s items stay on the board, in their band, side by side',
        (_, style) => {
            const sample = style.layout(1);
            const aspect = sample.height / sample.width;

            for (const [mechanic, { slots: band }] of builds) {
                for (const count of [2, 3, 4]) {
                    for (const tail of [null, '.']) {
                        const g = frameGeometry({
                            count,
                            band,
                            aspect,
                            tail,
                            palette: [0x3b82c4],
                        });
                        const label = `${mechanic} × ${count} × ${tail}`;

                        expect(g.xs, label).toHaveLength(count);
                        expect(g.inner.width, label).toBeGreaterThan(120);
                        expect(g.inner.height / g.inner.width).toBeCloseTo(
                            aspect,
                        );
                        expect(g.xs[0] - g.frame.width / 2).toBeGreaterThan(0);
                        expect(
                            (g.tail?.x ?? g.xs[count - 1] + g.frame.width / 2) +
                                (tail === null ? 0 : 40),
                        ).toBeLessThan(1920);
                        expect(g.y - g.frame.height / 2).toBeGreaterThanOrEqual(
                            band.top,
                        );
                        expect(g.y + g.frame.height / 2).toBeLessThanOrEqual(
                            band.bottom,
                        );

                        for (let index = 1; index < count; index++) {
                            expect(
                                g.xs[index] - g.xs[index - 1],
                            ).toBeGreaterThan(g.frame.width);
                        }
                    }
                }
            }
        },
    );

    it('fits the slot train, a wagon per slot behind the locomotive, on the board', () => {
        for (const [mechanic, { slots: band }] of builds) {
            for (const count of [2, 3, 4]) {
                for (const tail of [null, '?']) {
                    const g = trainGeometry({
                        count,
                        band,
                        aspect: 0.8,
                        tail,
                        palette: [0x3b82c4],
                    });
                    const label = `${mechanic} × ${count} × ${tail}`;

                    expect(g.slots, label).toHaveLength(count);
                    expect(g.width, label).toBeGreaterThan(180);
                    expect(g.left, label).toBeGreaterThan(0);
                    expect(g.right, label).toBeLessThan(1920);
                    expect(g.locomotive).toBeLessThan(g.slots[0]);
                    expect(g.tail === null).toBe(tail === null);
                    // The locomotive's cab is the tallest part of the train.
                    expect(
                        g.y - g.body / 2 - g.width * 0.3 - 18,
                    ).toBeGreaterThanOrEqual(band.top);
                    expect(g.rail + 36).toBeLessThanOrEqual(band.bottom);

                    for (let index = 1; index < count; index++) {
                        expect(
                            g.slots[index] - g.slots[index - 1],
                        ).toBeGreaterThan(g.width);
                    }
                }
            }
        }
    });
});
