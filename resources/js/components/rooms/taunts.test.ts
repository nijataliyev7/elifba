import { describe, expect, it } from 'vite-plus/test';
import type { RoomState } from '@/rooms/contract';
import {
    readTauntsHidden,
    shownTaunts,
    TAUNT_POP_MIN_MS,
    TAUNT_POP_STAGGER_MS,
    TAUNT_REACTION_MS,
    TAUNT_SHOW_MS,
    tauntMoment,
    tauntPops,
    tauntsEnd,
    TAUNTS_HIDDEN_KEY,
    TAUNTS_UNSEEN,
    throwChoice,
    trayView,
    writeTauntsHidden,
} from './taunts';

/*
 * Taunts on a page (docs/frontend.md rule 15 "Taunts", ADR 029 decisions
 * 36–43 as amended 2026-11-19): which reactions a page draws of the
 * state's taunts and for how long, when one pops in and how, what the
 * strip's throw button and its fan say, and the device's own choice to
 * hide them. The numbers are the server's; these only read them.
 */

const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/rooms/state.*.json',
    { eager: true, import: 'default' },
);

function state(name: string): RoomState {
    return fixtures[
        `../../../../tests/Fixtures/rooms/state.${name}.json`
    ] as RoomState;
}

const taunt = (id: string, sentMs: number) => ({
    id,
    from: '01kaqrx2ab3c4d5e6f7g8h9jkm',
    taunt: 'turtle',
    sent_ms: sentMs,
});

/** A live state with its taunts changed. */
function withTaunts(
    live: RoomState,
    patch: Partial<NonNullable<RoomState['taunts']>>,
): RoomState {
    return { ...live, taunts: { ...live.taunts!, ...patch } } as RoomState;
}

function memory(): Storage & { data: Map<string, string> } {
    const data = new Map<string, string>();

    return {
        data,
        length: 0,
        clear: () => data.clear(),
        key: () => null,
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => void data.set(key, value),
        removeItem: (key) => void data.delete(key),
    };
}

describe('tauntMoment', () => {
    it('shows a taunt the page heard arrive once, however often the state comes again', () => {
        let seen = tauntMoment(TAUNTS_UNSEEN, []).seen;
        let moment = tauntMoment(seen, ['a']);

        expect(moment.arrived).toEqual(['a']);
        seen = moment.seen;

        // The same state again, or with the taunt beside a new one.
        moment = tauntMoment(seen, ['a']);
        expect(moment.arrived).toEqual([]);
        moment = tauntMoment(moment.seen, ['b', 'a']);
        expect(moment.arrived).toEqual(['b']);
        expect(tauntMoment(moment.seen, ['b', 'a']).arrived).toEqual([]);
    });

    it('draws a taunt the page opened on still', () => {
        const opened = tauntMoment(TAUNTS_UNSEEN, ['a', 'b']);

        expect(opened.arrived).toEqual([]);
        expect(tauntMoment(opened.seen, ['a', 'b']).arrived).toEqual([]);
        expect(tauntMoment(opened.seen, ['c', 'a']).arrived).toEqual(['c']);
    });
});

describe('shownTaunts', () => {
    it("draws a reaction until sent_ms + TAUNT_REACTION_MS by the server's clock", () => {
        const list = [taunt('a', 10_000)];

        expect(TAUNT_REACTION_MS).toBe(3_000);
        // A state still lists it a second longer: a late read draws what is left.
        expect(TAUNT_REACTION_MS).toBeLessThan(TAUNT_SHOW_MS);
        expect(shownTaunts(list, 10_000, false)).toEqual(list);
        expect(shownTaunts(list, 12_999, false)).toEqual(list);
        expect(shownTaunts(list, 13_000, false)).toEqual([]);
        // An estimate a little behind the server still draws what the state holds.
        expect(shownTaunts(list, 9_900, false)).toEqual(list);
        // Before the page knows the server's clock, nothing.
        expect(shownTaunts(list, null, false)).toEqual([]);
        expect(tauntsEnd(list)).toBe(13_000);
        expect(tauntsEnd([])).toBeNull();
    });

    it('puts two reactions one above the other, the older first, and a third once a place is free', () => {
        // Newest first, as the state lists them.
        const list = [
            taunt('c', 12_000),
            taunt('b', 11_000),
            taunt('a', 10_000),
        ];

        expect(shownTaunts(list, 12_000, false).map((t) => t.id)).toEqual([
            'a',
            'b',
        ]);
        // At 13 s the first is over: the third takes its place, below the second.
        expect(shownTaunts(list, 13_000, false).map((t) => t.id)).toEqual([
            'b',
            'c',
        ]);
        expect(shownTaunts(list, 15_000, false)).toEqual([]);
    });

    it('shows nothing while the screen hides taunts', () => {
        const storage = memory();
        const list = [taunt('a', 10_000)];

        expect(readTauntsHidden(storage)).toBe(false);
        writeTauntsHidden(storage, true);
        expect(TAUNTS_HIDDEN_KEY).toBe('elifba.rooms.screen.taunts_hidden');
        expect(storage.data.get(TAUNTS_HIDDEN_KEY)).toBe('1');
        expect(readTauntsHidden(storage)).toBe(true);
        expect(shownTaunts(list, 10_000, readTauntsHidden(storage))).toEqual(
            [],
        );
        writeTauntsHidden(storage, false);
        expect(storage.data.has(TAUNTS_HIDDEN_KEY)).toBe(false);

        // A phone's old key hides nothing: only the host turns taunts off (ADR 029 decision 55).
        storage.data.set('elifba.rooms.phone.taunts_hidden', '1');
        expect(readTauntsHidden(storage)).toBe(false);

        // A storage that refuses: not hidden, and nothing throws.
        const refusing = {
            getItem: () => {
                throw new Error('denied');
            },
            setItem: () => {
                throw new Error('denied');
            },
            removeItem: () => {
                throw new Error('denied');
            },
        };

        expect(readTauntsHidden(refusing)).toBe(false);
        expect(() => writeTauntsHidden(refusing, true)).not.toThrow();
        expect(readTauntsHidden(null)).toBe(false);
    });
});

describe('tauntPops', () => {
    it('pops a reaction the page saw arrive within the time it has left, two drawn together 0.26 s apart', () => {
        const pops = tauntPops(
            [taunt('a', 10_000), taunt('b', 10_100)],
            10_400,
        );

        expect(TAUNT_POP_STAGGER_MS).toBe(260);
        // It ends where its reaction ends: sent_ms + 3 s.
        expect(pops.get('a')).toEqual({ duration: 2_600, delay: 0 });
        expect(pops.get('b')).toEqual({
            duration: 13_100 - 10_400 - 260,
            delay: 260,
        });
        // Drawn first late, it still grows and fades, if quicker.
        expect(tauntPops([taunt('c', 10_000)], 12_900).get('c')).toEqual({
            duration: TAUNT_POP_MIN_MS,
            delay: 0,
        });
        expect(tauntPops([], 10_000).size).toBe(0);
    });
});

describe('trayView', () => {
    const rival = '01kaqrx2ab3c4d5e6f7g8h9jkm';

    it('says the balance, or why a throw would not be taken now', () => {
        const answer = state('answer');
        const now = answer.server_ms;

        expect(trayView(answer, rival, now)).toEqual({
            kind: 'tray',
            balance: 17,
            reason: null,
            cooling: null,
        });

        const cooling = withTaunts(answer, { ready_ms: now + 7_200 });

        expect(trayView(cooling, rival, now)).toEqual({
            kind: 'tray',
            balance: 17,
            reason: 'cooling',
            cooling: 8,
        });
        expect(trayView(cooling, rival, now + 7_200)).toMatchObject({
            reason: null,
            cooling: null,
        });

        const broke = withTaunts(answer, { balance: 0 });

        expect(trayView(broke, rival, now)).toMatchObject({
            reason: 'no_points',
        });
        // Before the first close nobody has a point: it says so.
        expect(trayView(state('countdown'), rival, now)).toMatchObject({
            reason: 'first_round',
            balance: 0,
        });
    });

    it('has no tray without a rival, a viewer who plays or an open throw', () => {
        const answer = state('answer');

        expect(trayView(answer, null, answer.server_ms)).toEqual({
            kind: 'none',
        });
        expect(
            trayView(
                withTaunts(answer, { balance: null }),
                rival,
                answer.server_ms,
            ),
        ).toEqual({ kind: 'none' });
        expect(
            trayView(
                withTaunts(answer, { open: false }),
                rival,
                answer.server_ms,
            ),
        ).toEqual({ kind: 'none' });
        expect(trayView(state('over'), rival, 0)).toEqual({ kind: 'none' });
        expect(trayView(state('lobby'), rival, 0)).toEqual({ kind: 'none' });
    });

    it("never reads a verdict, a solution or anyone's result before the reveal", () => {
        const answer = state('answer');
        const now = answer.server_ms;
        const shown = trayView(answer, rival, now);

        // Whatever the round's count of answers, the viewer's own answer or the ranking say, the tray says the same.
        for (const other of [
            {
                ...answer,
                round: {
                    ...answer.round!,
                    answered: !answer.round!.answered,
                    answered_count: 0,
                },
            } as RoomState,
            { ...answer, ranking: [...answer.ranking].reverse() },
            { ...answer, ranking: [] },
        ]) {
            expect(trayView(other, rival, now)).toEqual(shown);
        }

        // And the cards are the state's own list, whatever else the state holds.
        const taunted = state('taunted');

        expect(
            shownTaunts(taunted.taunts!.received, taunted.server_ms, false),
        ).toEqual(taunted.taunts!.received);
        expect(
            shownTaunts(
                { ...taunted, ranking: [] }.taunts!.received,
                taunted.server_ms,
                false,
            ),
        ).toEqual(taunted.taunts!.received);
    });
});

describe('throwChoice', () => {
    const resad = '01kaqrx2ab3c4d5e6f7g8h9jkm';
    const aygun = '01kaqrx3mn4p5q6r7s8t9vwxyz';
    const tural = '01kaqrx4bc5d6e7f8g9h0jkmnp';
    const gone = '01kaqrx5cd6e7f8g9h0jkmnpqr';
    const leyla = '01kaqrx6de7f8g9h0jkmnpqrst';

    /** A race of four present players and one who left, Aygün's phone: the ranking as the server ordered it. */
    function race(): RoomState {
        const answer = state('answer');

        return {
            ...answer,
            players: [
                ...answer.players.map((player) =>
                    player.id === tural ? { ...player, present: true } : player,
                ),
                {
                    ...answer.players[1],
                    id: leyla,
                    name: 'Leyla Həsənova',
                },
            ],
            ranking: [
                { player_id: gone, points: 20, place: 1 },
                { player_id: aygun, points: 17, place: 2 },
                { player_id: leyla, points: 12, place: 3 },
                { player_id: resad, points: 9, place: 4 },
                { player_id: tural, points: 0, place: null },
            ],
        } as RoomState;
    }

    const strip = (id: string) => ({ id, name: null });

    it("offers every present player but the viewer, in the ranking's order as the server gave it", () => {
        const choice = throwChoice(race(), strip(leyla), null);

        // Not oneself, not one who left (a host who does not play is one); never re-sorted by name or join order.
        expect(choice.targets).toEqual([
            { id: leyla, name: 'Leyla Həsənova' },
            { id: resad, name: 'Rəşad Quliyev' },
            { id: tural, name: 'Tural Əliyev' },
        ]);
        expect(choice.row).toBe(true);

        const reversed = {
            ...race(),
            ranking: [...race().ranking].reverse(),
        } as RoomState;

        expect(
            throwChoice(reversed, strip(leyla), null).targets.map(
                (one) => one.id,
            ),
        ).toEqual([tural, resad, leyla]);
    });

    it("picks the strip's rival first, else the first of the row, and then the thrower's pick", () => {
        expect(throwChoice(race(), strip(resad), null).target?.id).toBe(resad);
        // The strip's rival left holding a place: the first of the row.
        expect(throwChoice(race(), strip(gone), null).target?.id).toBe(leyla);
        expect(throwChoice(race(), strip(resad), tural).target).toEqual({
            id: tural,
            name: 'Tural Əliyev',
        });
        // A pick nobody may be thrown at is no pick.
        expect(throwChoice(race(), strip(resad), aygun).target?.id).toBe(resad);
        expect(throwChoice(race(), strip(resad), gone).target?.id).toBe(resad);
    });

    it('drops a picked player who leaves before a throw', () => {
        const left = {
            ...race(),
            players: race().players.map((player) =>
                player.id === tural ? { ...player, present: false } : player,
            ),
        } as RoomState;
        const choice = throwChoice(left, strip(leyla), tural);

        expect(choice.targets.map((one) => one.id)).toEqual([leyla, resad]);
        expect(choice.target?.id).toBe(leyla);
        expect(choice.row).toBe(true);
    });

    it('has no row with one target: a duel, a match, a race whose others left', () => {
        // A duel: the other present player alone.
        const duel = throwChoice(state('answer'), strip(resad), null);

        expect(duel).toEqual({
            targets: [{ id: resad, name: 'Rəşad Quliyev' }],
            target: { id: resad, name: 'Rəşad Quliyev' },
            row: false,
        });

        // A match: the other seat alone, though a watcher is present.
        const match = throwChoice(state('match.play'), strip(resad), tural);

        expect(match.targets.map((one) => one.id)).toEqual([resad]);
        expect(match.target?.id).toBe(resad);
        expect(match.row).toBe(false);

        // A race whose others left: the one still there.
        const alone = {
            ...race(),
            players: race().players.map((player) =>
                player.id === tural || player.id === leyla
                    ? { ...player, present: false }
                    : player,
            ),
        } as RoomState;

        expect(throwChoice(alone, strip(leyla), leyla)).toMatchObject({
            target: { id: resad },
            row: false,
        });
    });

    it('offers nobody to a watcher, a viewer who does not play or a strip with no rival', () => {
        expect(throwChoice(state('match.watch'), strip(resad), null)).toEqual({
            targets: [],
            target: null,
            row: false,
        });
        expect(
            throwChoice(
                { ...race(), me: { player_id: null, host: true } },
                strip(resad),
                null,
            ),
        ).toEqual({ targets: [], target: null, row: false });
        expect(throwChoice(race(), null, null)).toEqual({
            targets: [],
            target: null,
            row: false,
        });
    });
});
