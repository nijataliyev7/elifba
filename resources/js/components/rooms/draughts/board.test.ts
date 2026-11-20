import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomMatch } from '@/rooms/contract';
import {
    cellOf,
    clockLevel,
    clockText,
    crownedAt,
    isDark,
    moveRows,
    seatOf,
    shownLeft,
    slideOf,
    squareAt,
} from './board';
import play from '../../../../../tests/Fixtures/rooms/state.match.play.json';

/*
 * Drawing a match (docs/frontend.md rule 15 "Board games"): a square's
 * place on the board, turned round for black; the record's numbered rows;
 * the last move's slide; the clocks as shown, which count down only for
 * the side to move and decide nothing.
 */
function match(): Extract<RoomMatch, { phase: 'play' }> {
    const state = RoomState.parse(play);

    if (state.match?.phase !== 'play') {
        throw new Error('A match in play.');
    }

    return state.match;
}

describe('the board', () => {
    it('draws white at the bottom, and turns the board round for black', () => {
        expect(cellOf('a1', false)).toEqual({ col: 0, row: 7 });
        expect(cellOf('h8', false)).toEqual({ col: 7, row: 0 });
        expect(cellOf('a1', true)).toEqual({ col: 7, row: 0 });
        expect(cellOf('h8', true)).toEqual({ col: 0, row: 7 });

        for (const flipped of [false, true]) {
            for (const square of ['a1', 'c3', 'f6', 'h8', 'b8']) {
                const { col, row } = cellOf(square, flipped);

                expect(squareAt(col, row, flipped)).toBe(square);
            }
        }

        expect(isDark('a1')).toBe(true);
        expect(isDark('b1')).toBe(false);
        expect(isDark('h8')).toBe(true);
    });

    it('numbers the moves as the record does: white and the reply on one line', () => {
        expect(moveRows([])).toEqual([]);
        expect(moveRows(['c3-d4', 'f6-e5', 'd4:f6'])).toEqual([
            { number: 1, white: 'c3-d4', black: 'f6-e5' },
            { number: 2, white: 'd4:f6', black: null },
        ]);
    });

    it('slides a leg in 160 ms, and a long capture within 640 ms', () => {
        expect(
            slideOf({ path: ['c3', 'd4'], captured: [], crowned: false }),
        ).toEqual({ legMs: 160, ms: 160 });
        expect(
            slideOf({
                path: ['a1', 'c3', 'e5', 'g7', 'e5', 'c7'],
                captured: ['b2', 'd4', 'f6', 'd6', 'b6'],
                crowned: false,
            }).ms,
        ).toBeLessThanOrEqual(640);
    });

    it('crowns a man where it first reached its last row, even mid-capture', () => {
        expect(
            crownedAt(
                { path: ['e7', 'f8'], captured: [], crowned: true },
                'white',
            ),
        ).toBe(1);
        expect(
            crownedAt(
                {
                    path: ['c7', 'e5', 'c3', 'a1'],
                    captured: ['d6', 'd4', 'b2'],
                    crowned: true,
                },
                'black',
            ),
        ).toBe(3);
        expect(
            crownedAt(
                { path: ['g7', 'e5'], captured: ['f6'], crowned: false },
                'white',
            ),
        ).toBeNull();
    });

    it('counts down only the side to move, to its flag, for show', () => {
        const shown = match();
        const [white, black] = shown.sides;

        expect(shownLeft(shown, white, shown.flag_ms - 61_000)).toBe(61_000);
        expect(shownLeft(shown, white, shown.flag_ms + 5)).toBe(0);
        expect(shownLeft(shown, black, shown.flag_ms - 1)).toBe(black.left_ms);
        expect(shownLeft(shown, white, null)).toBe(white.left_ms);
        expect(seatOf(shown, white.player_id)).toBe(white);
        expect(seatOf(shown, 'nobody')).toBeNull();
    });

    it('writes a clock in minutes, and tenths in its last ten seconds', () => {
        expect(clockText(300_000, ',')).toBe('5:00');
        expect(clockText(292_001, ',')).toBe('4:53');
        expect(clockText(10_000, ',')).toBe('0:10');
        expect(clockText(7_449, ',')).toBe('0:07,4');
        expect(clockText(7_449, '.')).toBe('0:07.4');
        expect(clockText(0, ',')).toBe('0:00,0');
        expect(clockLevel({ base_ms: 300_000, increment_ms: 3_000 })).toEqual({
            minutes: 5,
            seconds: 3,
        });
    });
});
