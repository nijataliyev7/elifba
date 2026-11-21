import { describe, expect, it } from 'vite-plus/test';
import type { RoomAsk } from '@/rooms/contract';
import { tap, untap } from './taps';

/*
 * Taps in an answer (docs/frontend.md rule 15, "Taps"): the answer is sent
 * by the tap that makes `pick` items; a cell is taken back by a second
 * tap, a sequence's last item by «Geri».
 */
function ask(shape: RoomAsk['shape'], pick: number): RoomAsk {
    return {
        shape,
        pick,
        layout: shape === 'set' ? 'grid' : 'row',
        grid: shape === 'set' ? { cols: 2, rows: 2 } : null,
        // A choice of what comes next shows a row beside its options (B5).
        shown:
            shape === 'choice'
                ? [
                      {
                          id: 'eeeeeeee',
                          kind: 'number',
                          text: '5',
                          asset: null,
                          cell: null,
                      },
                  ]
                : [],
        items: ['aaaaaaaa', 'bbbbbbbb', 'cccccccc', 'dddddddd'].map(
            (id, index) => ({
                id,
                kind: shape === 'set' ? 'cell' : 'digit',
                text: shape === 'set' ? null : String(index),
                asset: null,
                cell:
                    shape === 'set' ? [index % 2, Math.floor(index / 2)] : null,
            }),
        ),
        clue: null,
        orders: [],
    };
}

describe('tap', () => {
    it('sends a sequence by the tap that makes its length, in the order tapped', () => {
        const row = ask('sequence', 3);
        let picked: string[] = [];

        for (const id of ['cccccccc', 'aaaaaaaa']) {
            const next = tap(row, picked, id);
            expect(next.send).toBe(false);
            picked = next.ids;
        }

        expect(tap(row, picked, 'dddddddd')).toEqual({
            ids: ['cccccccc', 'aaaaaaaa', 'dddddddd'],
            send: true,
        });
    });

    it('takes back a cell with a second tap, and only by «Geri» in a sequence', () => {
        const cells = ask('set', 3);
        const first = tap(cells, [], 'bbbbbbbb');
        const second = tap(cells, first.ids, 'dddddddd');
        expect(tap(cells, second.ids, 'bbbbbbbb')).toEqual({
            ids: ['dddddddd'],
            send: false,
        });

        const row = ask('sequence', 3);
        expect(tap(row, ['bbbbbbbb'], 'bbbbbbbb')).toEqual({
            ids: ['bbbbbbbb'],
            send: false,
        });
        expect(untap(['bbbbbbbb', 'aaaaaaaa'])).toEqual(['bbbbbbbb']);
        expect(untap([])).toEqual([]);
    });

    it('sends a set by the tap that makes its count, never before', () => {
        const cells = ask('set', 2);
        const first = tap(cells, [], 'aaaaaaaa');
        expect(first.send).toBe(false);
        expect(tap(cells, first.ids, 'cccccccc')).toEqual({
            ids: ['aaaaaaaa', 'cccccccc'],
            send: true,
        });
    });

    it('ignores an id the question does not hold', () => {
        expect(tap(ask('set', 2), ['aaaaaaaa'], 'zzzzzzzz')).toEqual({
            ids: ['aaaaaaaa'],
            send: false,
        });
    });

    it('takes no tap on an item shown with the question, which is never an answer', () => {
        expect(tap(ask('choice', 1), [], 'eeeeeeee')).toEqual({
            ids: [],
            send: false,
        });
        expect(tap(ask('choice', 1), [], 'bbbbbbbb')).toEqual({
            ids: ['bbbbbbbb'],
            send: true,
        });
    });
});
