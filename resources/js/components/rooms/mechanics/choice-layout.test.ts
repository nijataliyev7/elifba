import { describe, expect, it } from 'vite-plus/test';
import type { RoomItem } from '@/rooms/contract';
import {
    longestText,
    optionColumns,
    optionMark,
    rowFont,
} from './choice-layout';

/*
 * A choice round's layout and its reveal's marks (docs/frontend.md rule
 * 15): the same size for every option, a row that keeps to one line, and
 * marks read from the reveal only.
 */
function item(id: string, text: string): RoomItem {
    return { id, kind: 'number', text, asset: null, cell: null };
}

describe('optionColumns', () => {
    it('pairs four options and stacks five, so none stands apart', () => {
        expect(optionColumns(4)).toBe(2);
        expect(optionColumns(5)).toBe(1);
    });
});

describe('longestText', () => {
    it('counts the characters of the longest text, a letter of Azerbaijani as one', () => {
        expect(longestText([item('a', '7'), item('b', '999')])).toBe(3);
        expect(longestText([item('a', 'üzgüçülük')])).toBe(9);
        expect(longestText([])).toBe(1);
    });
});

describe('rowFont', () => {
    it('shrinks with more cells and longer numbers, never above the cap', () => {
        expect(rowFont(5, 2, '0.25rem', '1.5rem')).toBe(
            'min(1.5rem, calc((100cqw - 4 * 0.25rem) / 9.2))',
        );
        expect(rowFont(7, 3, '0.25rem', '1.5rem')).toBe(
            'min(1.5rem, calc((100cqw - 6 * 0.25rem) / 17.22))',
        );
    });

    it('fits seven cells of three figures on a 375px phone at a readable size', () => {
        // 343px of row (375 less two 16px gutters), six 4px gaps.
        const font = (343 - 6 * 4) / 17.22;

        expect(font).toBeGreaterThan(16);
        expect(3 * 0.62 * font + 0.6 * font).toBeLessThanOrEqual(
            (343 - 6 * 4) / 7,
        );
    });
});

describe('optionMark', () => {
    const solution = ['right'];

    it('marks the right option from the reveal alone', () => {
        expect(optionMark('right', solution, null)).toEqual({
            right: true,
            yours: false,
            tone: 'right',
        });
        expect(optionMark('other', solution, null)).toEqual({
            right: false,
            yours: false,
            tone: null,
        });
    });

    it('marks the viewer’s own option by the server’s verdict', () => {
        const wrong = {
            picked: ['other'],
            correct: false,
            points: 0,
            elapsed_ms: 2000,
            letters: 0,
        };

        expect(optionMark('other', solution, wrong)).toEqual({
            right: false,
            yours: true,
            tone: 'wrong',
        });
        expect(optionMark('right', solution, wrong).yours).toBe(false);

        const right = { ...wrong, picked: ['right'], correct: true };

        expect(optionMark('right', solution, right)).toEqual({
            right: true,
            yours: true,
            tone: 'right',
        });
    });
});
