import { describe, expect, it, vi } from 'vite-plus/test';
import { Effect } from '../contract';
import { Unit } from '../contract';
import {
    BUILD_LAYOUTS,
    COUNT_LAYOUT,
    COUNT_MAX,
    countPictures,
} from '../scenes/layouts';
import {
    EFFECTS,
    ITEM_STYLES,
    SLOT_STYLES,
    effect,
    itemStyle,
    slotStyle,
    styleOfUnit,
} from './registry';
import { FIGURE_TEXT } from './figure';
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
            for (const [mechanic, { slots: band, tiles }] of builds) {
                // The tiles' shape in their band, as the build scene measures it.
                const sample = style.layout(1, tiles);
                const aspect = sample.height / sample.width;

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

    it('draws figure items’ text at least as large as card’s and bubble’s in the same layout', () => {
        // `card.ts` and `bubble.ts` size their labels at these shares of the item's width.
        const CARD_TEXT = 0.46;
        const BUBBLE_TEXT = 0.42;
        const bands = [undefined, ...builds.map(([, { tiles }]) => tiles)];

        for (const band of bands) {
            for (const count of [1, 2, 3, 4, 5, 6]) {
                const label = `${count} in ${band === undefined ? 'the board' : `${band.top}–${band.bottom}`}`;

                expect(
                    ITEM_STYLES.animal.layout(count, band).width * FIGURE_TEXT,
                    label,
                ).toBeGreaterThanOrEqual(
                    ITEM_STYLES.card.layout(count, band).width * CARD_TEXT,
                );
                expect(
                    ITEM_STYLES.fruit.layout(count, band).width * FIGURE_TEXT,
                    label,
                ).toBeGreaterThanOrEqual(
                    ITEM_STYLES.bubble.layout(count, band).width * BUBBLE_TEXT,
                );
            }
        }
    });

    it('draws the picture style, and every round of the picture unit in it whatever the pack names (R5)', () => {
        expect(itemStyle('picture')).toBe(ITEM_STYLES.picture);

        for (const pack of Object.values(ITEM_STYLES)) {
            for (const unit of Unit.options) {
                expect(styleOfUnit(unit, pack), unit).toBe(
                    unit === 'picture' ? ITEM_STYLES.picture : pack,
                );
            }
        }

        // Fewer, larger tiles: two pictures are wider than two cards.
        expect(ITEM_STYLES.picture.layout(2).width).toBeGreaterThan(
            ITEM_STYLES.card.layout(2).width,
        );
    });

    it('fits ten pictures in the question band, in rows of five that never overlap', () => {
        const band = COUNT_LAYOUT.question;
        let size = 0;

        for (let count = 1; count <= COUNT_MAX; count++) {
            const layout = countPictures(count);

            // More apples never look smaller.
            size ||= layout.size;
            expect(layout.size, `${count}`).toBe(size);
            expect(layout.spots, `${count}`).toHaveLength(count);
            expect(new Set(layout.spots.map((spot) => spot.y)).size).toBe(
                count > 5 ? 2 : 1,
            );

            for (const [index, spot] of layout.spots.entries()) {
                expect(spot.x - size / 2).toBeGreaterThanOrEqual(0);
                expect(spot.x + size / 2).toBeLessThanOrEqual(1920);
                expect(spot.y - size / 2).toBeGreaterThanOrEqual(band.top);
                expect(spot.y + size / 2).toBeLessThanOrEqual(band.bottom);

                for (const other of layout.spots.slice(0, index)) {
                    const apart =
                        Math.abs(spot.x - other.x) > size ||
                        Math.abs(spot.y - other.y) > size;

                    expect(apart, `${count}: ${index}`).toBe(true);
                }
            }
        }

        expect(size).toBeGreaterThan(150);
        // A ten-frame: 7 is a full row and two under its first two.
        const seven = countPictures(7).spots;

        expect(seven[5].x).toBe(seven[0].x);
        expect(seven[6].x).toBe(seven[1].x);
        expect(band.bottom).toBeLessThanOrEqual(COUNT_LAYOUT.items.top);
    });

    it.each(Object.entries(ITEM_STYLES))(
        '%s lays out a count round’s numbers in their band, under the question',
        (_, style) => {
            const band = COUNT_LAYOUT.items;

            for (const count of [2, 3, 4]) {
                const { width, height, spots } = style.layout(count, band);

                expect(width).toBeGreaterThan(150);

                for (const spot of spots) {
                    expect(spot.y - height / 2).toBeGreaterThanOrEqual(
                        band.top,
                    );
                    expect(spot.y + height / 2).toBeLessThanOrEqual(
                        band.bottom,
                    );
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
