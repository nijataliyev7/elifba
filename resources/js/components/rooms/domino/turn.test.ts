import { describe, expect, it } from 'vite-plus/test';
import { RoomState } from '@/rooms/contract';
import type { RoomDomino } from '@/rooms/contract';
import {
    PLAY_LANDS_MS,
    PLAY_MS,
    STEP_MS,
    turnSteps,
    viewerSeat,
    yourPlay,
} from './turn';

/*
 * A domino game's turns (docs/frontend.md rule 15 "Domino", ADR 038
 * decisions 47, 51 and 89): the one timeline of a turn's steps — the play,
 * then `last.then` a step at a time — and whose turn it is, read from the
 * state alone.
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

const NAMES = [
    'away',
    'chosen',
    'countdown',
    'drew',
    'opener',
    'over',
    'play',
    'screen',
    'sheet.blocked',
    'sheet.finished',
    'sheet.tied',
    'sheet.void',
    'stopped',
    'watch',
];

describe('a turn’s steps', () => {
    it('settles a play as the owner chose it: lifted, flown and settled in 510 ms', () => {
        expect(PLAY_MS).toEqual({ lift: 90, fly: 280, settle: 140 });
        expect(PLAY_LANDS_MS).toBe(510);
    });

    it('reads every fixture: the play, then what followed it by itself, a step at a time', () => {
        expect(
            Object.keys(fixtures)
                .map((key) =>
                    key.split('state.domino.')[1].replace('.json', ''),
                )
                .sort(),
        ).toEqual(NAMES);

        const expected: Record<string, ReturnType<typeof turnSteps> | null> = {
            chosen: null,
            countdown: { steps: [], ends: 0 },
            opener: { steps: [], ends: 0 },
            away: {
                steps: [
                    {
                        kind: 'play',
                        seat: 1,
                        tile: '3-0',
                        end: 'left',
                        double: false,
                        at: 0,
                        lands: 510,
                    },
                ],
                ends: 510,
            },
            play: {
                steps: [
                    {
                        kind: 'play',
                        seat: 3,
                        tile: '0-0',
                        end: 'right',
                        double: true,
                        at: 0,
                        lands: 510,
                    },
                ],
                ends: 510,
            },
            drew: {
                steps: [
                    {
                        kind: 'play',
                        seat: 1,
                        tile: '3-2',
                        end: 'left',
                        double: false,
                        at: 0,
                        lands: 510,
                    },
                    { kind: 'draw', seat: 0, count: 4, at: 510 },
                ],
                ends: 510 + STEP_MS.draw,
            },
            'sheet.blocked': {
                steps: [
                    {
                        kind: 'play',
                        seat: 2,
                        tile: '2-0',
                        end: 'left',
                        double: false,
                        at: 0,
                        lands: 510,
                    },
                    { kind: 'pass', seat: 3, at: 510 },
                    { kind: 'pass', seat: 0, at: 510 + STEP_MS.pass },
                    { kind: 'pass', seat: 1, at: 510 + 2 * STEP_MS.pass },
                    { kind: 'pass', seat: 2, at: 510 + 3 * STEP_MS.pass },
                ],
                ends: 510 + 4 * STEP_MS.pass,
            },
            'sheet.tied': {
                steps: [
                    {
                        kind: 'play',
                        seat: 3,
                        tile: '2-1',
                        end: 'left',
                        double: false,
                        at: 0,
                        lands: 510,
                    },
                    { kind: 'pass', seat: 0, at: 510 },
                    { kind: 'pass', seat: 1, at: 510 + STEP_MS.pass },
                    { kind: 'pass', seat: 2, at: 510 + 2 * STEP_MS.pass },
                    { kind: 'pass', seat: 3, at: 510 + 3 * STEP_MS.pass },
                ],
                ends: 510 + 4 * STEP_MS.pass,
            },
        };

        for (const name of NAMES) {
            const found = state(name).domino;

            if (name === 'chosen') {
                expect(found).toBeNull();

                continue;
            }

            if (found === null) {
                throw new Error(name);
            }

            const steps = turnSteps(found);

            if (name in expected) {
                expect(steps, name).toEqual(expected[name]);
            } else {
                // A play that nothing followed: it lands, and the turn after it begins there.
                expect(steps.steps, name).toHaveLength(1);
                expect(steps.ends, name).toBe(PLAY_LANDS_MS);
            }
        }
    });

    it('takes a «Sən aç» at once, and a seat that drew and still passed in two steps', () => {
        const given: Pick<RoomDomino, 'last'> = {
            last: {
                seat: 0,
                kind: 'yield',
                tile: null,
                end: null,
                stood_in: false,
                // oxlint-disable-next-line unicorn/no-thenable -- the contract's key (ADR 038 decision 68): an array, never a function
                then: [],
            },
        };

        expect(turnSteps(given)).toEqual({
            steps: [{ kind: 'yield', seat: 0, at: 0 }],
            ends: 0,
        });

        const drew = domino('drew').last;
        const shut: Pick<RoomDomino, 'last'> = {
            last:
                drew === null || drew.kind !== 'play'
                    ? null
                    : {
                          ...drew,
                          // oxlint-disable-next-line unicorn/no-thenable -- the contract's key (ADR 038 decision 68): an array, never a function
                          then: [{ seat: 0, drew: 2, passed: true }],
                      },
        };

        expect(turnSteps(shut).steps.slice(1)).toEqual([
            { kind: 'draw', seat: 0, count: 2, at: 510 },
            { kind: 'pass', seat: 0, at: 510 + STEP_MS.draw },
        ]);
        expect(turnSteps(shut).ends).toBe(510 + STEP_MS.draw + STEP_MS.pass);
    });
});

describe('whose turn it is', () => {
    it('is the viewer’s on the phone of the seat to move alone, while it plays it', () => {
        const turns: Record<string, boolean> = {
            away: false, // the viewer is away, and not to move
            chosen: false,
            countdown: false,
            drew: true,
            opener: true,
            over: false,
            play: true,
            screen: false,
            'sheet.blocked': false,
            'sheet.finished': false,
            'sheet.tied': false,
            'sheet.void': false,
            stopped: false,
            watch: false,
        };

        for (const name of NAMES) {
            const found = state(name);
            // The screen has no viewer, though it reads with the host's account.
            const viewer = name === 'screen' ? null : found.me.player_id;

            expect(
                found.domino !== null && yourPlay(found.domino, viewer),
                name,
            ).toBe(turns[name]);
        }
    });

    it('is not the turn of a seat the server plays while its player is away', () => {
        const away = domino('away');
        const viewer = state('away').me.player_id;

        expect(viewerSeat(away, viewer)).toBe(1);
        expect(yourPlay({ ...away, turn: 1 }, viewer)).toBe(false);
        expect(
            yourPlay(
                {
                    ...away,
                    turn: 1,
                    seats: away.seats.map((seat) => ({
                        ...seat,
                        standing: 'playing' as const,
                    })),
                },
                viewer,
            ),
        ).toBe(true);
    });

    it('knows no seat for a watcher, a host who does not sit and the screen', () => {
        expect(
            viewerSeat(domino('watch'), state('watch').me.player_id),
        ).toBeNull();
        expect(viewerSeat(domino('screen'), null)).toBeNull();
        expect(viewerSeat(domino('opener'), state('opener').me.player_id)).toBe(
            2,
        );
    });
});
