import { describe, expect, it } from 'vite-plus/test';
import { nextShown } from '@/components/rooms/use-arrival';
import { RoomState } from '@/rooms/contract';
import type { RoomDomino, RoomPlayer } from '@/rooms/contract';
import { withPending } from './domino-table';
import { lineParts, turnBeginsAt } from './line';
import { dominoSeatName, dominoSideName, sideTone } from './names';
import {
    firstName,
    handRows,
    othersInTurn,
    partnerOf,
    seatPlaces,
} from './places';
import { sheetView, toTarget } from './sheet';
import { seatGroups, withTable, withTableSeat } from './table-choice';
import {
    endsOf,
    litTiles,
    putBack,
    tapTarget,
    tapTile,
    targetsOf,
} from './taps';
import { PLAY_LANDS_MS, STEP_MS, stepCounts } from './turn';

/*
 * The pure parts of a domino game's pages (docs/frontend.md rule 15
 * "Domino", ADR 038 decisions 44–53 and 86–90), each held on the
 * `state.domino.*` fixtures the server built from rows: the taps over
 * `mine.legal`, where the seats stand, a hand's rows, the choice of a table
 * and its seats, the sheet's sentences, the line under the table, how a
 * seat and a side are named and the counts a turn's steps change.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../../../tests/Fixtures/rooms/state.domino.*.json',
    { eager: true, import: 'default' },
);

function state(name: string) {
    return RoomState.parse(
        fixtures[
            `../../../../../tests/Fixtures/rooms/state.domino.${name}.json`
        ],
    );
}

function domino(name: string): RoomDomino {
    const found = state(name).domino;

    if (found === null) {
        throw new Error(`${name} has no domino`);
    }

    return found;
}

/** A translation that shows its key and its values, so a test reads what a page would say. */
const t = (key: string, values: Record<string, string | number> = {}) =>
    Object.keys(values).length === 0
        ? key
        : `${key}(${Object.entries(values)
              .map(([k, v]) => `${k}=${v}`)
              .join(',')})`;

describe('taps over mine.legal', () => {
    const legal = domino('play').mine?.legal ?? null;

    it('lights exactly the tiles mine.legal names', () => {
        expect([...litTiles(legal)].sort()).toEqual(['5-0', '5-2', '5-4']);
        expect(litTiles(null).size).toBe(0);
        expect(endsOf(legal, '6-4')).toBeNull();
    });

    it('posts a tile that fits one end at once, and a hand’s first tile alone', () => {
        expect(tapTile(legal, null, '5-2')).toEqual({
            picked: null,
            send: ['5-2', 'left'],
            sound: null,
        });

        const opening = domino('opener').mine?.legal ?? null;

        expect(tapTile(opening, null, '6-5').send).toEqual(['6-5']);
    });

    it('lifts a tile that fits both ends, lights both targets and posts the end tapped', () => {
        const lifted = tapTile(legal, null, '5-0');

        expect(lifted).toEqual({ picked: '5-0', send: null, sound: null });
        expect(targetsOf(legal, '5-0')).toEqual(['left', 'right']);
        expect(tapTarget(legal, '5-0', 'right').send).toEqual(['5-0', 'right']);
        // Another lit tile starts again with it.
        expect(tapTile(legal, '5-0', '5-4').send).toEqual(['5-4', 'left']);
    });

    it('does nothing for a tile mine.legal does not offer, or a target with nothing lifted', () => {
        expect(tapTile(legal, '5-0', '6-4')).toEqual({
            picked: '5-0',
            send: null,
            sound: null,
        });
        expect(tapTarget(legal, null, 'left').send).toBeNull();
        expect(targetsOf(legal, '5-2')).toEqual([]);
    });

    it('puts a lifted tile back with undo, and nothing without one', () => {
        expect(putBack('5-0')).toEqual({
            picked: null,
            send: null,
            sound: 'undo',
        });
        expect(putBack(null).sound).toBeNull();
    });
});

describe('where things stand', () => {
    it('puts the viewer at the bottom and the others clockwise, a partner opposite', () => {
        expect(seatPlaces(4, 1)).toEqual(['right', 'bottom', 'left', 'top']);
        expect(seatPlaces(4, null)).toEqual(['bottom', 'left', 'top', 'right']);
        expect(seatPlaces(3, 2)).toEqual(['left', 'right', 'bottom']);
        expect(seatPlaces(2, 1)).toEqual(['top', 'bottom']);
        expect(othersInTurn(4, 1)).toEqual([2, 3, 0]);
        expect(othersInTurn(2, null)).toEqual([1]);
        expect(partnerOf('pairs', 3)).toBe(1);
        expect(partnerOf('four', 3)).toBeNull();
    });

    it('stands a hand in rows in the order dealt: one up to 7, two from 8, wider beyond 14', () => {
        const tiles = (count: number) =>
            Array.from({ length: count }, (_, index) => `t${index}`);

        expect(handRows(tiles(7))).toEqual({
            bottom: tiles(7),
            top: [],
            across: 7,
        });
        expect(handRows(tiles(9))).toEqual({
            bottom: tiles(5),
            top: ['t5', 't6', 't7', 't8'],
            across: 5,
        });
        expect(handRows(tiles(14)).across).toBe(7);
        expect(handRows(tiles(17)).across).toBe(9);
        expect(firstName(' Aygün Məmmədova ')).toBe('Aygün');
    });
});

describe('the choice of a table', () => {
    const choice = {
        mechanic: 'domino_101' as const,
        difficulty: 2,
        table: 'pairs' as const,
        seats: ['a', 'b', 'c', 'd'],
        computer: null,
    };

    it('fits the seats to another table, as the room would', () => {
        expect(withTable(choice, 'three').seats).toEqual(['a', 'b', 'c']);
        expect(withTable({ ...choice, seats: ['a'] }, 'four').seats).toEqual([
            'a',
            null,
            null,
            null,
        ]);
    });

    it('changes places for a seated player and seats a watcher in the place it takes', () => {
        expect(withTableSeat(choice, 0, 'c').seats).toEqual([
            'c',
            'b',
            'a',
            'd',
        ]);
        expect(withTableSeat(choice, 1, 'e').seats).toEqual([
            'a',
            'e',
            'c',
            'd',
        ]);
        expect(withTableSeat(choice, 2, 'c')).toBe(choice);
    });

    it('groups the seats by pair at «Para 2×2» only', () => {
        expect(seatGroups('pairs', 4)).toEqual([
            [0, 2],
            [1, 3],
        ]);
        expect(seatGroups('four', 4)).toEqual([[0, 1, 2, 3]]);
    });
});

describe('the sheet', () => {
    const named = (seat: number) => `seat${seat}`;

    it('says a blocked hand’s ending, its counts and why it was won, from the state’s numbers', () => {
        const view = sheetView(domino('sheet.blocked'), named);

        expect(view?.how.key).toBe('rooms.domino.sheet.blocked');
        expect(view?.counts).toEqual([
            { side: 0, pips: 23 },
            { side: 1, pips: 29 },
        ]);
        expect(view?.lower).toEqual({
            key: 'rooms.domino.sheet.lower',
            params: { low: 23, high: 29 },
        });
        expect(view?.lines.map((line) => [line.key, line.params])).toEqual([
            ['rooms.domino.sheet.line.opened', { count: 29 }],
        ]);
        expect(view?.next).toEqual({
            key: 'rooms.domino.sheet.next',
            params: {
                name: 'seat2',
                why: 'rooms.domino.sheet.why.fewest',
            },
        });
    });

    it('names who finished, and the lowest double while the next deal decides', () => {
        expect(sheetView(domino('sheet.finished'), named)?.how).toEqual({
            key: 'rooms.domino.sheet.finished',
            params: { name: `seat${domino('sheet.finished').sheet?.by}` },
        });
        expect(sheetView(domino('sheet.tied'), named)?.next?.key).toBe(
            'rooms.domino.sheet.next_double',
        );
        expect(sheetView(domino('sheet.void'), named)?.lines).toEqual([]);
    });

    it('marks a burnt line, says no next hand once the game is over, and nothing outside a sheet', () => {
        const over = sheetView(domino('over'), named);

        expect(over?.next).toBeNull();
        expect(over?.until).toBeNull();
        expect(sheetView(domino('play'), named)).toBeNull();
        expect(
            sheetView(
                {
                    sheet: {
                        ...(domino('sheet.blocked').sheet ?? never()),
                        lines: [{ side: 1, line: 'burnt_rival', points: 8 }],
                    },
                },
                named,
            )?.lines[0].burnt,
        ).toBe(true);
        expect(toTarget(86, 101)).toBe(15);
        expect(toTarget(120, 101)).toBe(0);
    });

    it('says «0-0 tək qalıb» only for a seat left with 0-0 alone', () => {
        const sheet = domino('sheet.blocked').sheet ?? never();
        const view = sheetView(
            {
                sheet: {
                    ...sheet,
                    left: [
                        { seat: 0, tiles: ['0-0'], pips: 10 },
                        { seat: 1, tiles: ['0-0', '1-0'], pips: 1 },
                    ],
                },
            },
            named,
        );

        expect(view?.left.map((seat) => seat.loneBlank)).toEqual([true, false]);
    });
});

function never(): never {
    throw new Error('a fixture without its part');
}

describe('the line under the table', () => {
    const input = (viewer: string | null) => ({
        viewer,
        picking: false,
        failed: false,
        seconds: 3,
        nameOf: (seat: number) => `seat${seat}`,
    });

    it('is the band for the seat to move, and asks for an end while a tile is lifted', () => {
        const play = state('play');
        const parts = lineParts(
            play.domino ?? never(),
            input(play.me.player_id),
        );

        expect(parts.map((part) => [part.key, part.band])).toEqual([
            ['rooms.domino.your_turn', true],
        ]);
        expect(
            lineParts(play.domino ?? never(), {
                ...input(play.me.player_id),
                picking: true,
            }).at(-1)?.key,
        ).toBe('rooms.domino.pick_end');
        expect(
            lineParts(play.domino ?? never(), {
                ...input(play.me.player_id),
                failed: true,
            }).at(-1),
        ).toMatchObject({ key: 'rooms.domino.play_failed', wrong: true });
    });

    it('says what the viewer drew before its turn, and whom a watcher waits for', () => {
        const drew = state('drew');

        expect(
            lineParts(drew.domino ?? never(), input(drew.me.player_id)).map(
                (part) => [part.key, part.params],
            ),
        ).toEqual([
            ['rooms.domino.drew_own', { count: 4 }],
            ['rooms.domino.your_turn', {}],
        ]);

        const watch = state('watch');

        expect(
            lineParts(watch.domino ?? never(), input(watch.me.player_id)).map(
                (part) => part.key,
            ),
        ).toEqual(['rooms.domino.watching', 'rooms.domino.their_turn']);
        // The screen is nobody's device: no «Oyunu izləyirsiniz».
        expect(
            lineParts(state('screen').domino ?? never(), {
                ...input(null),
                screen: true,
            }).map((part) => part.key),
        ).toEqual(['rooms.domino.their_turn']);
    });

    it('opens a hand with any tile for a seat with the right, and counts the countdown', () => {
        const opener = state('opener');

        expect(
            lineParts(opener.domino ?? never(), input(opener.me.player_id)).at(
                -1,
            )?.key,
        ).toBe('rooms.domino.open_any');

        const countdown = state('countdown');

        expect(
            lineParts(
                countdown.domino ?? never(),
                input(countdown.me.player_id),
            ),
        ).toEqual([
            {
                id: 'starts',
                key: 'rooms.domino.starts_in',
                params: { seconds: 3 },
                icon: 'hourglass',
            },
        ]);
    });

    it('says a turn the server played for a player, and gives the away seat no band', () => {
        const away = state('away');
        const parts = lineParts(
            away.domino ?? never(),
            input(away.me.player_id),
        );

        expect(parts.map((part) => part.key)).toEqual([
            'rooms.domino.stood_in',
            'rooms.domino.their_turn',
        ]);
        expect(parts.some((part) => part.band === true)).toBe(false);
    });

    it('begins the turn when the steps before it end', () => {
        expect(turnBeginsAt(domino('drew'))).toBe(PLAY_LANDS_MS + STEP_MS.draw);
        expect(turnBeginsAt(domino('opener'))).toBe(0);
    });
});

describe('names and sides', () => {
    const fixture = state('sheet.blocked');
    const players: RoomPlayer[] = fixture.players;
    const d = fixture.domino ?? never();

    it('names a pair by its two first names, and «Biz»/«Onlar» on a seat’s phone', () => {
        expect(dominoSideName(t, players, d, null, null, 'pair:0')).toBe(
            'Rəşad · Tural',
        );
        expect(
            dominoSideName(t, players, d, null, fixture.me.player_id, 'pair:1'),
        ).toBe('rooms.rivalry.us');
        expect(
            dominoSideName(t, players, d, null, fixture.me.player_id, 'pair:0'),
        ).toBe('rooms.rivalry.them');
        expect(dominoSeatName(t, players, d, null, 3, true)).toBe('Leyla');
    });

    it('names the computer’s seat with its level, and colours the viewer’s side sky', () => {
        const computer = {
            ...d,
            seats: d.seats.map((seat) =>
                seat.seat === 3
                    ? {
                          ...seat,
                          player_id: null,
                          standing: 'computer' as const,
                      }
                    : seat,
            ),
        };

        expect(dominoSeatName(t, players, computer, 2, 3)).toBe(
            'rooms.computer.named(level=rooms.computer.level.2)',
        );
        expect(sideTone(d, fixture.me.player_id, 1)).toBe('left');
        expect(sideTone(d, fixture.me.player_id, 0)).toBe('right');
        expect(sideTone(d, null, 0)).toBe('left');
        expect(sideTone(d, null, null)).toBeNull();
    });
});

describe('what a turn’s steps change', () => {
    it('counts the boneyard down and the drawing seat up at its step, ending at the state’s counts', () => {
        const d = domino('drew');

        expect(stepCounts(d)).toEqual({
            pile: [
                { from: 0, value: 14 },
                { from: PLAY_LANDS_MS, value: 10 },
            ],
            seats: [
                [
                    { from: 0, value: 5 },
                    { from: PLAY_LANDS_MS, value: 9 },
                ],
                [{ from: 0, value: 5 }],
            ],
        });
    });

    it('changes nothing without steps', () => {
        const counts = stepCounts(domino('watch'));

        expect(counts.pile).toEqual([{ from: 0, value: 0 }]);
        expect(counts.seats.every((seat) => seat.length === 1)).toBe(true);
    });
});

describe('the viewer’s posted tile at its end', () => {
    it('lies at the end it was posted to, its end’s number against the line', () => {
        const d = domino('play');
        const left = d.ends.find((end) => end.end === 'left')?.number;
        const right = d.ends.find((end) => end.end === 'right')?.number;

        expect(withPending(d, null)).toBe(d.line);
        expect(withPending(d, ['5-0', 'left'])[0]).toEqual({
            tile: '5-0',
            left: left === 5 ? 0 : 5,
            right: left,
        });
        expect(withPending(d, ['5-0', 'right']).at(-1)).toEqual({
            tile: '5-0',
            left: right,
            right: right === 5 ? 0 : 5,
        });
        expect(withPending({ line: [], ends: [] }, ['6-5'])).toEqual([
            { tile: '6-5', left: 6, right: 5 },
        ]);
    });
});

describe('the act a page saw arrive', () => {
    it('arrives at one ply more in the same game, and keeps what it drew at the same ply', () => {
        const arrive = (before: string) => `after ${before}`;
        const opened = nextShown(null, 'g', 4, 'x', arrive);

        expect(opened.arrival).toBeNull();

        const next = nextShown(opened, 'g', 5, 'y', arrive);

        expect(next.arrival).toBe('after x');
        expect(nextShown(next, 'g', 5, 'z', arrive)).toBe(next);
        expect(nextShown(opened, 'g', 6, 'y', arrive).arrival).toBeNull();
        expect(nextShown(opened, 'h', 5, 'y', arrive).arrival).toBeNull();
    });
});
