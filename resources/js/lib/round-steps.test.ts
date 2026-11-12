import { describe, expect, it } from 'vite-plus/test';
import { roundSteps } from './round-steps';

describe('roundSteps', () => {
    it('offers multiples of 5, and a game count that is none of them', () => {
        const limits = { min: 5, max: 30 };

        expect(roundSteps(limits, 10)).toEqual([5, 10, 15, 20, 25, 30]);
        expect(roundSteps(limits, 7)).toEqual([5, 7, 10, 15, 20, 25, 30]);
        expect(roundSteps(limits, 40)).toEqual([5, 10, 15, 20, 25, 30]);
        expect(roundSteps({ min: 3, max: 12 }, 12)).toEqual([5, 10, 12]);
    });
});
