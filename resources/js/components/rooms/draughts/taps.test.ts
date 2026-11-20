import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import {
    completeMove,
    nextSquares,
    pathsFrom,
    startsOf,
    tapSquare,
    untapAll,
} from './taps';
import play from '../../../../../tests/Fixtures/rooms/state.match.play.json';

/*
 * A seat's taps (docs/frontend.md rule 15 "Board games" → "Taps"): a tap
 * lifts a piece that begins one of the state's paths, adds a square the
 * path may land on next, and posts the path once it is whole; anything
 * else takes the taps back. Only the server's paths are ever offered.
 */

/** A king on `a1` with two captures that share their first landing, and a man on `c3` with a quiet move. */
const PATHS = [
    ['a1', 'c3', 'e5'],
    ['a1', 'c3', 'a5'],
    ['g1', 'h2'],
];

describe('the taps over the state paths', () => {
    it('lifts only a piece that begins a path', () => {
        expect([...startsOf(PATHS)]).toEqual(['a1', 'g1']);
        expect(tapSquare(PATHS, [], 'a1')).toEqual({
            taps: ['a1'],
            send: null,
            sound: 'tap',
        });
        // A piece that begins no path, or an empty square, does nothing.
        expect(tapSquare(PATHS, [], 'e3')).toEqual({
            taps: [],
            send: null,
            sound: null,
        });
    });

    it('offers the next landings of the begun paths, and nothing before a piece is lifted', () => {
        expect([...nextSquares(PATHS, [])]).toEqual([]);
        expect([...nextSquares(PATHS, ['a1'])]).toEqual(['c3']);
        expect([...nextSquares(PATHS, ['a1', 'c3'])]).toEqual(['e5', 'a5']);
        expect(pathsFrom(PATHS, ['a1', 'c3'])).toHaveLength(2);
    });

    it('sends a path once the taps name it whole, and never a part of one', () => {
        const first = tapSquare(PATHS, ['a1'], 'c3');

        expect(first).toEqual({ taps: ['a1', 'c3'], send: null, sound: 'tap' });
        expect(completeMove(PATHS, ['a1', 'c3'])).toBeNull();

        const whole = tapSquare(PATHS, first.taps, 'a5');

        expect(whole.send).toEqual(['a1', 'c3', 'a5']);
        expect(tapSquare(PATHS, ['g1'], 'h2').send).toEqual(['g1', 'h2']);
    });

    it('starts again from another piece, and takes the taps back elsewhere', () => {
        expect(tapSquare(PATHS, ['a1', 'c3'], 'g1')).toEqual({
            taps: ['g1'],
            send: null,
            sound: 'tap',
        });
        // The lifted piece again, or a square no path lands on next.
        expect(tapSquare(PATHS, ['a1'], 'a1')).toEqual({
            taps: [],
            send: null,
            sound: 'undo',
        });
        expect(tapSquare(PATHS, ['a1', 'c3'], 'd4')).toEqual({
            taps: [],
            send: null,
            sound: 'undo',
        });
        expect(untapAll(['a1', 'c3'])).toEqual({
            taps: [],
            send: null,
            sound: 'undo',
        });
        expect(untapAll([]).sound).toBeNull();
    });

    it('lets a capture cross a square again (the Turkish strike) since the paths say so', () => {
        // A king's path that comes back over its own start.
        const loop = [['e1', 'a5', 'd8', 'h4', 'e1', 'c3']];

        const taps = ['e1', 'a5', 'd8', 'h4'];

        expect([...nextSquares(loop, taps)]).toEqual(['e1']);
        expect(tapSquare(loop, taps, 'e1').taps).toEqual([...taps, 'e1']);
        expect(tapSquare(loop, [...taps, 'e1'], 'c3').send).toEqual(loop[0]);
    });

    it('offers exactly the compulsory capture of the state that has one', () => {
        const state = RoomState.parse(play);
        const paths = state.match?.legal?.paths ?? [];

        expect(state.match?.legal?.capture).toBe(true);
        expect([...startsOf(paths)]).toEqual(['d4']);
        expect(tapSquare(paths, [], 'c3').taps).toEqual([]);
        expect(tapSquare(paths, ['d4'], 'f6').send).toEqual(['d4', 'f6']);
    });
});
