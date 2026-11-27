import { createElement } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vite-plus/test';
import type { RoomDomino } from '@/rooms/contract';
import { layoutChain, PHONE_CHAIN } from './chain';
import { ChainSvg } from './domino-table';

/*
 * The line's shadow (ADR 038 "As built (Y8)"): Safari (WebKit) left out
 * pips of tiles drawn inside a filtered group — on an iPhone a double on
 * the table had none and a lying tile only its bottom row — so the
 * shadow is a layer of its own, cast from outlines, and no pip is drawn
 * under a filter.
 */

const PIPS = [0, 1, 2, 3, 4, 5, 6];

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

/** The markup inside the first element that opens at `from`, its own tag included. */
function element(markup: string, from: number): string {
    const tag = /<(\/?)g\b[^>]*?(\/?)>/g;
    tag.lastIndex = from;
    let depth = 0;

    for (
        let match = tag.exec(markup);
        match !== null;
        match = tag.exec(markup)
    ) {
        if (match[2] === '/') {
            continue;
        }

        depth += match[1] === '/' ? -1 : 1;

        if (depth === 0) {
            return markup.slice(from, tag.lastIndex);
        }
    }

    throw new Error('unclosed group');
}

describe('the line on the table', () => {
    const played = line('6:6 6:3 3:4 4:4 4:2 2:5 5:5 5:1 1:0');
    const markup = renderToStaticMarkup(
        createElement(ChainSvg, {
            layout: layoutChain(played, {
                width: 351,
                height: 288,
                ...PHONE_CHAIN,
            }),
            width: 351,
            height: 288,
            last: '1-0',
            pending: null,
            hidden: null,
            targets: [],
            label: 'Domino masası',
        }),
    );

    it('draws no pip inside the filtered shadow', () => {
        const at = markup.indexOf('filter="url(#dm-shadow)"');
        const shadow = element(markup, markup.lastIndexOf('<g', at));

        expect(at).toBeGreaterThan(-1);
        expect(shadow).not.toContain('url(#dm-pip)');
        expect(shadow).not.toContain('data-tile');
        expect(markup.split('filter=').length - 1).toBe(1);
    });

    it('draws every pip of every tile outside it', () => {
        const pips = played.reduce(
            (sum, tile) => sum + PIPS[tile.left] + PIPS[tile.right],
            0,
        );

        expect(markup.split('url(#dm-pip)').length - 1).toBe(pips);
        expect(markup.split('data-tile=').length - 1).toBe(played.length);
    });

    it('draws no badge by the ends: the pips say them (decision 101)', () => {
        expect(markup).not.toContain('<text');
        expect(markup).not.toContain('var(--card)');
        expect(markup).toContain('aria-label="Domino masası"');
    });
});
