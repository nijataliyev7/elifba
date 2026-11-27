import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomDomino, RoomDominoSheet } from '@/rooms/contract';
import ledger from '../../../../../tests/Fixtures/rooms/domino/ledger.json';
import {
    balanceChanges,
    sideKey,
    sideOfKey,
    sidePlayers,
    viewerSide,
} from './sides';

/*
 * A domino game's sides on a page (docs/frontend.md rule 15 "Domino" →
 * "The strip", ADR 038 decisions 48 and 57): a side is keyed by its one
 * seat or as a pair, and what a hand wrote to a balance is read from its
 * sheet's lines — the oracle's ledger holds it hand by hand.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../../tests/Fixtures/rooms/state.domino.*.json',
    { eager: true, import: 'default' },
);

function domino(name: string): RoomDomino {
    const found = RoomState.parse(
        fixtures[
            `../../../../../tests/Fixtures/rooms/state.domino.${name}.json`
        ],
    ).domino;

    if (found === null) {
        throw new Error(`${name} has no domino`);
    }

    return found;
}

const RESAD = '01kaqrx2ab3c4d5e6f7g8h9jkm';
const AYGUN = '01kaqrx3mn4p5q6r7s8t9vwxyz';
const TURAL = '01kaqrx4bc5d6e7f8g9h0jkmnp';
const LEYLA = '01kaqrx5cd6e7f8g9h0jkmnpqr';

describe('a domino game’s sides', () => {
    it('keys a pair by itself and a side of one seat by its seat — a player’s id or the computer’s seat', () => {
        const pairs = domino('play');
        const two = domino('away');

        expect(pairs.sides.map((side) => sideKey(pairs, side))).toEqual([
            'pair:0',
            'pair:1',
        ]);
        expect(two.sides.map((side) => sideKey(two, side))).toEqual([
            RESAD,
            AYGUN,
        ]);
        expect(sidePlayers(pairs, pairs.sides[1])).toEqual([AYGUN, LEYLA]);
        expect(sideOfKey(pairs, 'pair:1')?.seats).toEqual([1, 3]);

        const computers: RoomDomino = {
            ...pairs,
            seats: pairs.seats.map((seat) =>
                seat.seat === 3
                    ? {
                          ...seat,
                          player_id: null,
                          standing: 'computer' as const,
                      }
                    : seat,
            ),
        };

        expect(sidePlayers(computers, computers.sides[1])).toEqual([AYGUN]);

        const three: RoomDomino = {
            ...pairs,
            seats: computers.seats
                .slice(0, 4)
                .map((seat) => ({ ...seat, side: seat.seat })),
            sides: [0, 1, 2, 3].map((side) => ({
                ...pairs.sides[0],
                side,
                seats: [side],
                place: 1,
            })),
        };

        expect(three.sides.map((side) => sideKey(three, side))).toEqual([
            RESAD,
            AYGUN,
            TURAL,
            'computer:3',
        ]);
    });

    it('finds the viewer’s side, and none for a watcher or the screen', () => {
        expect(viewerSide(domino('play'), RESAD)?.side).toBe(0);
        expect(viewerSide(domino('play'), LEYLA)?.side).toBe(1);
        expect(
            viewerSide(domino('watch'), '01kaqrx6de7f8g9h0jkmnpqrst'),
        ).toBeUndefined();
        expect(viewerSide(domino('screen'), null)).toBeUndefined();
    });

    it('reads what a hand wrote to each balance from its sheet’s lines, nothing during a hand', () => {
        expect(balanceChanges(domino('play'))).toBeNull();
        expect(balanceChanges(domino('countdown'))).toBeNull();
        expect(balanceChanges(domino('stopped'))).toBeNull();
        expect(balanceChanges(domino('sheet.finished'))).toEqual([
            { side: 1, points: 16 },
            { side: 0, points: 0 },
        ]);
        expect(balanceChanges(domino('sheet.blocked'))).toEqual([
            { side: 0, points: 29 },
            { side: 1, points: 0 },
        ]);
        // A tie's pips go to the bank, which is nobody's; a void hand writes nothing.
        expect(balanceChanges(domino('sheet.tied'))).toEqual([
            { side: 0, points: 0 },
            { side: 1, points: 0 },
        ]);
        expect(balanceChanges(domino('sheet.void'))).toEqual([
            { side: 0, points: 0 },
            { side: 1, points: 0 },
        ]);
    });

    it('agrees with the oracle’s ledger: every hand’s lines add up to what each balance moved by', () => {
        type Account = {
            balance: number;
            open: boolean;
            waiting: number;
            tries: number;
        };
        type After = {
            sides: Account[];
            bank: number;
            lines: RoomDominoSheet['lines'];
        };

        const sequences = (
            ledger as {
                sequences: { id: string; sides: number; after: After[] }[];
            }
        ).sequences;
        let hands = 0;

        expect(sequences.length).toBeGreaterThanOrEqual(22);

        for (const sequence of sequences) {
            let before = Array.from({ length: sequence.sides }, () => 0);

            for (const after of sequence.after) {
                const sides = after.sides.map((account, side) => ({
                    side,
                    seats: [side],
                    balance: account.balance,
                    open: account.open,
                    waiting: account.waiting,
                    tries: account.tries,
                    place: 1,
                }));
                const sheet = { lines: after.lines } as RoomDominoSheet;

                expect(
                    balanceChanges({ sheet, sides })?.map(
                        (change) => change.points,
                    ),
                    sequence.id,
                ).toEqual(
                    after.sides.map(
                        (account, side) => account.balance - before[side],
                    ),
                );

                before = after.sides.map((account) => account.balance);
                hands++;
            }
        }

        expect(hands).toBeGreaterThan(100);
    });
});
