import { describe, expect, it } from 'vitest';
import {
    clockLabel,
    completeMove,
    movesFrom,
    nextSquares,
    piecesOf,
    timeLeft,
} from '@/components/rooms/checkers/match';
import type { RoomMatch } from '@/components/rooms/checkers/match';

describe('a board match on the page', () => {
    const moves = [
        [42, 28, 14],
        [42, 28, 12],
        [44, 35],
    ];

    it('offers the next squares of the moves that begin with the taps', () => {
        expect([...nextSquares(moves, [])]).toEqual([42, 44]);
        expect([...nextSquares(moves, [42])]).toEqual([28]);
        expect([...nextSquares(moves, [42, 28])]).toEqual([14, 12]);
        expect(movesFrom(moves, [44])).toEqual([[44, 35]]);
    });

    it('names a move only once its whole path is tapped', () => {
        expect(completeMove(moves, [42, 28])).toBeNull();
        expect(completeMove(moves, [42, 28, 12])).toEqual([42, 28, 12]);
        expect(completeMove(moves, [44, 35])).toEqual([44, 35]);
    });

    it('counts the pieces of a side', () => {
        expect(piecesOf('wW.b', 'w')).toBe(2);
        expect(piecesOf('wW.bB', 'b')).toBe(2);
    });
});

describe("a match's clock", () => {
    const match = {
        turn: 'w',
        over: false,
        clock: { total_ms: 300_000, white_ms: 120_000, black_ms: 90_000 },
    } as RoomMatch;

    it("runs only the side to move's time, from the state's moment", () => {
        expect(timeLeft(match, 'w', 1_000, 6_000)).toBe(115_000);
        expect(timeLeft(match, 'b', 1_000, 6_000)).toBe(90_000);
        expect(timeLeft(match, 'w', 1_000, 999_000)).toBe(0);
        expect(timeLeft({ ...match, over: true }, 'w', 1_000, 6_000)).toBe(
            120_000,
        );
    });

    it('shows minutes and seconds, rounding up', () => {
        expect(clockLabel(300_000)).toBe('5:00');
        expect(clockLabel(65_001)).toBe('1:06');
        expect(clockLabel(9_000)).toBe('0:09');
        expect(clockLabel(0)).toBe('0:00');
    });
});
