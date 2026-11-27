import { describe, expect, it } from 'vite-plus/test';
import { notesRow } from '@/components/rooms/rivalry';
import { RoomState } from '@/rooms/contract';
import type { RoomDomino } from '@/rooms/contract';
import {
    chipLines,
    dealtNow,
    flies,
    flightFrames,
    flyReady,
    handPlaces,
    markBelow,
    offEdge,
    PHONE_CHIP_PX,
    PHONE_PX,
    phoneTable,
    sheetColumns,
    stepKey,
    tableArrival,
} from './stage';
import { PLAY_LANDS_MS } from './turn';

/*
 * Where and when a domino page draws its parts (docs/frontend.md rule 15
 * "Domino"; ADR 038 "As built (Y7)": the audit's page findings), held on
 * the `state.domino.*` fixtures where a state is needed.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../../tests/Fixtures/rooms/state.domino.*.json',
    { eager: true, import: 'default' },
);

function domino(name: string): RoomDomino {
    const found =
        fixtures[`../../../../../tests/Fixtures/rooms/state.${name}.json`];
    const parsed = RoomState.parse(found);

    if (parsed.domino === null) {
        throw new Error(`${name} has no domino`);
    }

    return parsed.domino;
}

describe('the phone at 375 × 667 (d1.10)', () => {
    it('counts the frame bar with its border, and the hand at 108px', () => {
        expect(PHONE_PX.bar).toBe(57);
        expect(PHONE_PX.hand).toBe(108);
    });

    it('leaves the table at least its 200px at 667 and at an installed 647, so nothing scrolls', () => {
        expect(phoneTable(667)).toBeGreaterThanOrEqual(PHONE_PX.table);
        expect(phoneTable(647)).toBeGreaterThanOrEqual(PHONE_PX.table);
        expect(
            PHONE_PX.bar +
                PHONE_PX.strip +
                PHONE_PX.line +
                PHONE_PX.hand +
                phoneTable(667),
        ).toBe(667);
    });
});

describe("the viewer's own play (d2.1)", () => {
    const arrival = { seat: 0, tile: '6-5', key: 'g:12' };

    it('does not fly, but keeps its moment so the steps after it are drawn', () => {
        expect(tableArrival(arrival, true)).toEqual({
            seat: 0,
            tile: null,
            key: 'g:12',
        });
    });

    it("leaves another seat's play as it arrived", () => {
        expect(tableArrival(arrival, false)).toBe(arrival);
        expect(tableArrival(null, true)).toBeNull();
    });
});

describe('the deal (c2.3, d2.3)', () => {
    it('deals only in the moment the hand opened, before its first act', () => {
        const opened = { ...domino('domino.play'), last: null };

        expect(dealtNow(true, opened)).toBe(true);
    });

    it('never later in the hand: a tile that comes later is no deal', () => {
        const later = domino('domino.drew');

        expect(later.last).not.toBeNull();
        expect(dealtNow(true, later)).toBe(false);
    });

    it('never on a page that did not see the hand open', () => {
        expect(dealtNow(false, { ...domino('domino.play'), last: null })).toBe(
            false,
        );
    });
});

describe('a play flies once (c2.1, d1.2)', () => {
    it('is drawn while it flies and never once it landed', () => {
        expect(flies('g:12', null)).toBe(true);
        expect(flies('g:12', 'g:11')).toBe(true);
        expect(flies('g:12', 'g:12')).toBe(false);
        expect(flies(null, null)).toBe(false);
    });

    it('waits until the table was measured at the size it has', () => {
        expect(
            flyReady({ width: 359, height: 362 }, { width: 359, height: 264 }),
        ).toBe(false);
        expect(
            flyReady({ width: 359, height: 264 }, { width: 359, height: 264 }),
        ).toBe(true);
    });
});

describe('the flight moves in transform and opacity only (c2.2, d2.6)', () => {
    const frames = flightFrames([180, 400], [60, 90], 90, 20);

    it('animates nothing else, the tile nor its shadow', () => {
        for (const frame of [...frames.tile, ...frames.shadow]) {
            expect(
                Object.keys(frame).filter(
                    (key) =>
                        key !== 'transform' &&
                        key !== 'opacity' &&
                        key !== 'offset',
                ),
            ).toEqual([]);
        }
    });

    it('takes the play its 510 ms and ends where the line has the tile', () => {
        expect(frames.duration).toBe(PLAY_LANDS_MS);
        expect(frames.tile.at(-1)?.transform).toBe(
            'translate(50px, 70px) rotate(90deg) scale(1)',
        );
        expect(frames.tile.at(-1)?.offset).toBe(1);
    });
});

describe("a step's mark on a chip (d1.1, d2.2)", () => {
    it("stands under a phone's chips along the table's top, over the bottom row's", () => {
        expect(markBelow(false, 'top', false)).toBe(true);
        expect(markBelow(false, 'left', false)).toBe(true);
        expect(markBelow(false, 'right', false)).toBe(true);
        expect(markBelow(false, 'bottom', true)).toBe(false);
    });

    it("stands under the screen's top seat and over the others", () => {
        expect(markBelow(true, 'top', false)).toBe(true);
        expect(markBelow(true, 'left', false)).toBe(false);
        expect(markBelow(true, 'right', false)).toBe(false);
        expect(markBelow(true, 'bottom', true)).toBe(false);
    });

    it('is keyed by its moment, so a seat that passes again shows it again', () => {
        const pass = { kind: 'pass', seat: 1, at: 510 };

        expect(stepKey('g:30', pass)).not.toBe(stepKey('g:31', pass));
        expect(stepKey('g:30', pass)).toBe(stepKey('g:30', { ...pass }));
    });
});

describe('the hand in one list (c2.3, d2.3, d1.8)', () => {
    const names = (count: number) =>
        [
            '6-6',
            '6-5',
            '6-4',
            '6-3',
            '6-2',
            '6-1',
            '6-0',
            '5-5',
            '5-4',
            '5-3',
            '5-2',
            '5-1',
            '5-0',
            '4-4',
            '4-3',
            '4-2',
            '4-1',
            '4-0',
            '3-3',
        ].slice(0, count);

    it('stands seven in one row', () => {
        const { places, rows } = handPlaces(names(7));

        expect(rows).toBe(1);
        expect(places.map((place) => place.row)).toEqual(Array(7).fill(1));
        expect(places.map((place) => place.column)).toEqual([
            1, 3, 5, 7, 9, 11, 13,
        ]);
    });

    it('splits eight and more into two rows, the first half at the bottom, the rest centred over it', () => {
        const { places, rows, across } = handPlaces(names(9));

        expect(rows).toBe(2);
        expect(across).toBe(5);
        expect(
            places
                .filter((place) => place.row === 2)
                .map((place) => place.tile),
        ).toEqual(names(5));
        // Four over five: half a tile in.
        expect(
            places
                .filter((place) => place.row === 1)
                .map((place) => place.column),
        ).toEqual([2, 4, 6, 8]);
    });

    it('keeps every tile once, in the order dealt and drawn, whichever row it stands in', () => {
        for (const count of [7, 8, 9, 14, 15, 19]) {
            expect(
                handPlaces(names(count))
                    .places.map((place) => place.tile)
                    .sort(),
            ).toEqual([...names(count)].sort());
        }
    });

    it('counts the tiles past the edge, not the columns', () => {
        // 16 tiles: 8 across, two rows; 7 seen whole leaves the last of each row past the edge.
        expect(offEdge(names(16), 7)).toBe(2);
        // 19 tiles: 10 at the bottom, 9 above, half a tile in; 7 seen whole.
        expect(offEdge(names(19), 7)).toBe(6);
        expect(offEdge(names(19), 10)).toBe(0);
    });
});

describe("a seat's chip (d1.4)", () => {
    it('is two lines on a phone, so three chips across 375px keep their names', () => {
        expect(chipLines(false, false)).toBe(2);
    });

    it("is one line on the screen but a side seat's, which wraps", () => {
        expect(chipLines(true, false)).toBe(1);
        expect(chipLines(true, true)).toBe(2);
    });

    it("leaves the line's box 46px inside a phone's table, under the chips' two lines", () => {
        expect(PHONE_CHIP_PX.height).toBe(36);
        expect(
            PHONE_CHIP_PX.edge + PHONE_CHIP_PX.height + PHONE_CHIP_PX.gap,
        ).toBe(46);
    });
});

describe('the sheet (d1.5, d1.6)', () => {
    it("stands in two columns on the screen's panel", () => {
        expect(sheetColumns(true, false)).toBe(2);
    });

    it('stands in one on a phone and under the final', () => {
        expect(sheetColumns(false, false)).toBe(1);
        expect(sheetColumns(true, true)).toBe(1);
        expect(sheetColumns(false, true)).toBe(1);
    });
});

describe("the accounts' words in the strip (d1.3)", () => {
    it('take a row of their own on a phone, and the gap row on the screen', () => {
        expect(notesRow(false)).toBe('below');
        expect(notesRow(true)).toBe('gap');
    });
});
