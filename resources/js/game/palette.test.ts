import { describe, expect, it } from 'vite-plus/test';
import { boardColours, overWhite, textOn } from './palette';

describe('board colours', () => {
    it('come from the pack palette', () => {
        expect(boardColours(['#E85D4A', '#F6C445'])).toEqual({
            background: '#FDF2F1',
            accent: '#E85D4A',
            text: '#1F2937',
        });
    });

    it('fall back to white and dark text without a usable colour', () => {
        expect(boardColours(['red'])).toEqual({
            background: overWhite('#1F2937', 0.08),
            accent: '#1F2937',
            text: '#1F2937',
        });
        expect(overWhite('nope', 0.5)).toBe('#FFFFFF');
    });

    it('pick readable text', () => {
        expect(textOn('#F6C445')).toBe('#1F2937');
        expect(textOn('#3B82C4')).toBe('#FFFFFF');
    });
});
