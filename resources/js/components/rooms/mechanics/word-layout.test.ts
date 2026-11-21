import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomAsk, RoomLetters, RoomMine } from '@/rooms/contract';
import {
    anagramMine,
    canShuffle,
    lettersOf,
    placesOf,
    priceKey,
    rowOf,
    slotText,
    tileRows,
    trayOrder,
    trayOrders,
} from './word-layout';

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
    return {
        picked,
        correct,
        points: correct ? 19 : 0,
        elapsed_ms: 2300,
        letters: 0,
    };
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

/** The anagram's states as the contract's fixtures have them: «alma» before a purchase and after its «A». */
const fixtures = import.meta.glob<unknown>(
    '../../../../../tests/Fixtures/rooms/state.answer.anagram*.json',
    { eager: true, import: 'default' },
);

function answerRound(name: string) {
    const state = RoomState.parse(
        fixtures[`../../../../../tests/Fixtures/rooms/${name}.json`],
    );

    if (state.round?.phase !== 'answer') {
        throw new Error(`${name} is not an answer phase`);
    }

    return state.round;
}

const fresh = answerRound('state.answer.anagram');
const afterOne = answerRound('state.answer.anagram.bought');
const text = (round: typeof fresh, ids: string[]) =>
    lettersOf(round.ask, ids).join('');

describe('trayOrders and trayOrder («Qarışdır»)', () => {
    it("cycles through the question's orders, then back to the first", () => {
        const orders = trayOrders(fresh.ask, fresh.letters);

        expect(orders).toBe(fresh.ask.orders);
        expect(canShuffle(orders)).toBe(true);
        expect(
            [0, 1, 2, 3, 4, 5].map((turns) =>
                text(fresh, trayOrder(orders, turns)),
            ),
        ).toEqual(['MALA', 'LAMA', 'AMLA', 'LMAA', 'MALA', 'LAMA']);
        // The first order is the tiles as the question lays them out.
        expect(trayOrder(orders, 0)).toEqual(
            fresh.ask.items.map((item) => item.id),
        );
    });

    it("cycles through the buyer's own orders once a letter is bought, never the bought tile", () => {
        const letters = afterOne.letters as RoomLetters;
        const orders = trayOrders(afterOne.ask, letters);

        expect(orders).toBe(letters.orders);
        expect(
            [0, 1, 2].map((turns) => text(afterOne, trayOrder(orders, turns))),
        ).toEqual(['ALM', 'MLA', 'ALM']);

        for (const turns of [0, 1, 2, 3]) {
            expect(trayOrder(orders, turns)).not.toContain(letters.bought[0]);
        }
    });

    it('shows no order once every letter is bought, and one with no shuffle for an older round', () => {
        const all: RoomLetters = {
            bought: ['b7q1w8ne', 'r2z6c0lu', 't4k9m2xa', 'h5y3j9dp'],
            orders: [],
            price: null,
        };

        expect(trayOrders(afterOne.ask, all)).toEqual([]);
        expect(trayOrder([], 3)).toEqual([]);

        const older = { ...fresh.ask, clue: null, orders: [] };

        expect(trayOrders(older, null)).toEqual([
            older.items.map((item) => item.id),
        ]);
        expect(canShuffle(trayOrders(older, null))).toBe(false);
    });
});

describe('placesOf and rowOf', () => {
    it("fills the places with the viewer's taps, the next empty one marked", () => {
        const places = placesOf(fresh.ask, [], ['r2z6c0lu']);

        expect(places).toEqual([
            { kind: 'tapped', id: 'r2z6c0lu', text: 'L' },
            { kind: 'empty', next: true },
            { kind: 'empty', next: false },
            { kind: 'empty', next: false },
        ]);
    });

    it('locks the bought letters in the first places, before the taps', () => {
        const letters = afterOne.letters as RoomLetters;
        const places = placesOf(afterOne.ask, letters.bought, ['r2z6c0lu']);

        expect(places.map((place) => place.kind)).toEqual([
            'bought',
            'tapped',
            'empty',
            'empty',
        ]);
        expect(places[0]).toEqual({
            kind: 'bought',
            id: 'b7q1w8ne',
            text: 'A',
        });
        expect(places[2]).toEqual({ kind: 'empty', next: true });
        expect(rowOf(letters.bought, ['r2z6c0lu'])).toEqual([
            'b7q1w8ne',
            'r2z6c0lu',
        ]);
    });

    it('never lays a bought tile twice, and fills the word when every letter is bought', () => {
        const bought = ['b7q1w8ne', 'r2z6c0lu', 't4k9m2xa', 'h5y3j9dp'];

        expect(rowOf(['b7q1w8ne'], ['b7q1w8ne', 'h5y3j9dp'])).toEqual([
            'b7q1w8ne',
            'h5y3j9dp',
        ]);
        expect(
            placesOf(afterOne.ask, bought, []).every(
                (place) => place.kind === 'bought',
            ),
        ).toBe(true);
        expect(text(afterOne, rowOf(bought, []))).toBe('ALMA');
    });
});

describe('priceKey', () => {
    it("says the state's word for the next letter, never a number", () => {
        expect(priceKey(fresh.letters)).toBe('rooms.anagram.price.half');
        expect(priceKey(afterOne.letters)).toBe('rooms.anagram.price.rest');
        expect(
            priceKey({
                bought: ['b7q1w8ne', 'r2z6c0lu'],
                orders: [['h5y3j9dp', 't4k9m2xa']],
                price: 'none',
            }),
        ).toBe('rooms.anagram.price.none');
    });

    it('offers nothing to buy without letters of the viewer or once every letter is bought', () => {
        expect(priceKey(null)).toBeNull();
        expect(
            priceKey({
                bought: ['b7q1w8ne', 'r2z6c0lu', 't4k9m2xa', 'h5y3j9dp'],
                orders: [],
                price: null,
            }),
        ).toBeNull();
    });
});
