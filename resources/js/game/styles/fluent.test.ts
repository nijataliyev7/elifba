import { describe, expect, it } from 'vite-plus/test';
import { STEP, mix, step, tones } from './fluent';

/*
 * The Fluent manner of the item and slot styles (docs/architecture.md §2,
 * "The Fluent manner"; ADR 014's own-art style guide): shade turns towards
 * red and violet and light towards yellow, never grey; and no item or slot
 * style draws an outline.
 */

/** Hue (degrees), saturation and lightness (0–1) of a colour. */
function hsl(colour: number): { h: number; s: number; l: number } {
    const r = ((colour >> 16) & 0xff) / 255;
    const g = ((colour >> 8) & 0xff) / 255;
    const b = (colour & 0xff) / 255;
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;
    const d = max - min;

    if (d === 0) {
        return { h: 0, s: 0, l };
    }

    const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    const h =
        max === r
            ? ((g - b) / d + (g < b ? 6 : 0)) * 60
            : max === g
              ? ((b - r) / d + 2) * 60
              : ((r - g) / d + 4) * 60;

    return { h, s, l };
}

/** The packs' palette colours today (content/packs/*.json `style.palette`). */
const PALETTE = [
    0xe85d4a, 0xf6c445, 0x3b82c4, 0x5db075, 0x9b5de5, 0x8d6e4c, 0x2ba6a0,
    0x00b5e2, 0xef3340, 0x509e2f,
];

describe('the Fluent tones', () => {
    it.each(PALETTE.map((colour) => [colour.toString(16), colour]))(
        '%s: light is lighter, shade and deep darker and never greyer',
        (_, colour) => {
            const base = hsl(colour);
            const t = tones(colour);

            expect(t.base).toBe(colour);
            expect(hsl(t.light).l).toBeGreaterThan(base.l);
            expect(hsl(t.shade).l).toBeLessThan(base.l);
            expect(hsl(t.deep).l).toBeLessThan(hsl(t.shade).l);
            expect(hsl(t.shade).s).toBeGreaterThanOrEqual(base.s - 0.01);
            expect(hsl(t.deep).s).toBeGreaterThanOrEqual(base.s - 0.01);
        },
    );

    it('turns shade towards red and violet', () => {
        // Blue turns up towards violet, red down towards crimson.
        expect(hsl(tones(0x3b82c4).shade).h).toBeGreaterThan(hsl(0x3b82c4).h);
        expect(hsl(tones(0xe85d4a).shade).h).toBeGreaterThan(300);
    });

    it('keeps a yellow yellow, not orange', () => {
        for (const yellow of [0xf6c445, 0xfcea2b]) {
            const { h } = hsl(tones(yellow).shade);

            expect(h).toBeGreaterThan(35);
            expect(h).toBeLessThan(65);
        }
    });

    it('leans a grey towards violet', () => {
        const { h, s } = hsl(tones(0x3f3f3f).shade);

        expect(s).toBeGreaterThan(0.05);
        expect(h).toBeGreaterThan(250);
        expect(h).toBeLessThan(300);
    });

    it('softens the tones of a pale surface', () => {
        const full = tones(0x3b82c4);
        const soft = tones(0x3b82c4, 0.3);

        expect(hsl(soft.shade).l).toBeGreaterThan(hsl(full.shade).l);
    });

    it('mixes colours', () => {
        expect(mix(0x000000, 0xffffff, 0.5)).toBe(0x808080);
        expect(mix(0x3b82c4, 0x3b82c4, 0.7)).toBe(0x3b82c4);
    });

    it('rounds texture sizes up to its step', () => {
        expect(step(1)).toBe(STEP);
        expect(step(300)).toBe(304);
        expect(step(304)).toBe(304);
        expect(step(312.2)).toBe(320);
    });
});

/** The modules that draw an item or a slot (`styles/registry.ts`), as text. */
const sources = import.meta.glob<string>(
    [
        './balloon.ts',
        './wagon.ts',
        './card.ts',
        './bubble.ts',
        './figure.ts',
        './animal.ts',
        './fruit.ts',
        './picture.ts',
        './slot-wagon.ts',
        './slot-frame.ts',
        '../scenes/build-word.ts',
    ],
    { query: '?raw', import: 'default', eager: true },
);

describe('the item and slot styles', () => {
    it('reads every style', () => {
        expect(Object.keys(sources)).toHaveLength(11);
    });

    it.each(Object.entries(sources))('%s draws no outline', (_, source) => {
        expect(source).not.toMatch(
            /\b(lineStyle|setStrokeStyle|strokeRoundedRect|strokeRect|strokeCircle|strokeEllipse)\(/,
        );
    });
});
