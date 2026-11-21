import { describe, expect, it } from 'vite-plus/test';
import type { RoomAsk, RoomMine } from '@/rooms/contract';
import { anagramMine, lettersOf, slotText, tileRows } from './word-layout';

/*
 * The word games' layout and the anagram's reveal (docs/frontend.md rule
 * 15): tiles in even rows that fit a 375px phone, letters as the server
 * cased them, and a verdict read from the reveal only.
 */

/** «ALMA» and «AMAL» from the same four tiles, as the fixture has them. */
const ask: RoomAsk = {
    shape: 'sequence',
    pick: 4,
    layout: 'row',
    grid: null,
    shown: [],
    items: [
        { id: 'm', kind: 'letter', text: 'M', asset: null, cell: null },
        { id: 'a1', kind: 'letter', text: 'A', asset: null, cell: null },
        { id: 'l', kind: 'letter', text: 'L', asset: null, cell: null },
        { id: 'a2', kind: 'letter', text: 'A', asset: null, cell: null },
    ],
    clue: null,
    orders: [],
};
const solution = ['a1', 'l', 'm', 'a2'];

function mine(picked: string[], correct: boolean): RoomMine {
    return { picked, correct, points: correct ? 19 : 0, elapsed_ms: 2300 };
}

describe('tileRows', () => {
    it('keeps five 60px tiles to a row at 375px and shares the letters out evenly', () => {
        expect(tileRows(4, 5)).toEqual({ rows: 1, perRow: 4 });
        expect(tileRows(5, 5)).toEqual({ rows: 1, perRow: 5 });
        expect(tileRows(6, 5)).toEqual({ rows: 2, perRow: 3 });
        expect(tileRows(7, 5)).toEqual({ rows: 2, perRow: 4 });
        expect(tileRows(8, 5)).toEqual({ rows: 2, perRow: 4 });
        expect(tileRows(11, 5)).toEqual({ rows: 3, perRow: 4 });
        expect(tileRows(0, 5)).toEqual({ rows: 0, perRow: 0 });
    });

    it('fits the longest word of the hardest level on a 375px phone', () => {
        // 8 letters, the hardest anagram level; the page's column is 343px.
        const { perRow } = tileRows(8, 5);

        expect(perRow * 60 + (perRow - 1) * 8).toBeLessThanOrEqual(343);
    });
});

describe('slotText', () => {
    it('keeps a word of up to nine letters at the large size', () => {
        expect(slotText(8)).toBe('text-2xl');
        expect(slotText(9)).toBe('text-2xl');
        expect(slotText(11)).toBe('text-xl');
    });
});

describe('lettersOf', () => {
    it('writes the letters as the tiles have them, cased on the server', () => {
        const dotted: RoomAsk = {
            ...ask,
            items: [
                { id: 'i', kind: 'letter', text: 'İ', asset: null, cell: null },
                { id: 'ı', kind: 'letter', text: 'I', asset: null, cell: null },
            ],
        };

        expect(lettersOf(dotted, ['i', 'ı'])).toEqual(['İ', 'I']);
        expect(lettersOf(ask, ['missing'])).toEqual(['']);
    });
});

describe('anagramMine', () => {
    it('shows another word the letters spell as right, never as a mistake', () => {
        // «AMAL»: the server said right; the round's word is «ALMA».
        expect(
            anagramMine(ask, solution, mine(['a2', 'm', 'a1', 'l'], true)),
        ).toBe('other');
    });

    it('takes the same word with the other «A» first as the same word', () => {
        expect(
            anagramMine(ask, solution, mine(['a2', 'l', 'm', 'a1'], true)),
        ).toBe('same');
    });

    it('reads right and wrong from the server alone', () => {
        // The order of the solution itself, judged wrong, is drawn as wrong:
        // the page never overrules the server.
        expect(anagramMine(ask, solution, mine(solution, false))).toBe('wrong');
        expect(anagramMine(ask, solution, null)).toBeNull();
    });
});
