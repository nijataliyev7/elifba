import { describe, expect, it } from 'vite-plus/test';
import type { z } from 'zod';
import {
    ROOM_STATE_VERSION,
    RoomAct,
    RoomActReceipt,
    RoomAnswer,
    RoomAnswerReceipt,
    RoomChangedEvent,
    RoomItem,
    RoomState,
    RoomTaunt,
    RoomTauntReceipt,
} from './contract';

/*
 * Every fixture in tests/Fixtures/rooms parses with its strict schema; Pest
 * round-trips the same files through the PHP DTOs
 * (tests/Unit/Rooms/RoomContractTest.php) and builds the lobby from rows
 * (tests/Feature/Rooms/RoomStateTest.php). The prefix of a file name names
 * its schema. judge.json holds the cases of the server's judge, not a
 * payload a device receives: only its items are read here.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/rooms/*.json',
    { eager: true, import: 'default' },
);

const schemas: Record<string, z.ZodType> = {
    state: RoomState,
    answer: RoomAnswer,
    receipt: RoomAnswerReceipt,
    taunt: RoomTaunt,
    'taunt-receipt': RoomTauntReceipt,
    act: RoomAct,
    'act-receipt': RoomActReceipt,
};

function schemaOf(path: string): z.ZodType | undefined {
    const name = path.split('/').pop() ?? '';

    return schemas[name.slice(0, name.indexOf('.'))];
}

function fixture(name: string): unknown {
    return fixtures[`../../../tests/Fixtures/rooms/${name}.json`];
}

type State = z.infer<typeof RoomState>;
type Round = NonNullable<State['round']>;

const lobby = fixture('state.lobby') as State;
const payloads = Object.entries(fixtures).filter(
    ([path]) => !path.endsWith('/judge.json'),
);

describe('room fixtures', () => {
    it('accepts the lobby fixture', () => {
        const result = RoomState.safeParse(lobby);

        expect(result.error).toBeUndefined();
        expect(result.data).toEqual(lobby);
    });

    it('has a fixture for every phase, the answer and the receipt', () => {
        for (const name of [
            'state.lobby',
            'state.countdown',
            'state.study',
            'state.answer',
            'state.reveal',
            'state.over',
            'answer',
            'receipt',
            'state.study.cells',
            'state.answer.cells',
            'state.reveal.cells',
            'state.study.shapes',
            'state.answer.sequence',
            'state.reveal.sequence',
            'state.answer.odd',
            'state.reveal.odd',
            'state.answer.anagram',
            'state.reveal.anagram',
            'answer.anagram',
            'state.answer.spelling',
            'state.reveal.spelling',
            'answer.spelling',
            'state.chosen',
            'state.taunted',
            'state.screen-taunted',
            'taunt',
            'taunt-receipt',
            'state.voice',
            'state.voice.off',
            'state.match.chosen',
            'state.match.countdown',
            'state.match.play',
            'state.match.watch',
            'state.match.offer',
            'state.match.over',
            'act',
            'act-receipt',
        ]) {
            expect(fixture(name), name).toBeDefined();
        }
    });

    it('names the word of a word game in the reveal alone', () => {
        for (const game of ['anagram', 'spelling']) {
            const answer = fixture(`state.answer.${game}`) as State;
            const reveal = fixture(`state.reveal.${game}`) as State;
            const asked = answer.round as Extract<Round, { phase: 'answer' }>;
            const revealed = (
                reveal.round as Extract<Round, { phase: 'reveal' }>
            ).reveal;

            expect(revealed.word, game).not.toBeNull();
            expect(JSON.stringify(answer), game).not.toContain('"word":');

            // The word in the question's own phase is refused.
            expect(
                RoomState.safeParse({
                    ...answer,
                    round: { ...asked, word: revealed.word },
                }).success,
            ).toBe(false);
        }

        // An anagram's tiles never spell the word; a spelling shows it among four.
        const anagram = (fixture('state.answer.anagram') as State)
            .round as Extract<Round, { phase: 'answer' }>;
        const spelling = (fixture('state.answer.spelling') as State)
            .round as Extract<Round, { phase: 'answer' }>;

        expect(anagram.ask.items.map((item) => item.text).join('')).not.toBe(
            'ALMA',
        );
        expect(spelling.ask.items).toHaveLength(4);
        expect(spelling.ask.items.map((item) => item.text)).toContain('kitab');
    });

    it.each(payloads)('%s parses strictly', (path, data) => {
        const schema = schemaOf(path);
        const result = schema?.safeParse(data);

        expect(schema, path).toBeDefined();
        expect(result?.error).toBeUndefined();
        expect(result?.data).toEqual(data);
    });

    it("reads the judge cases' items as items", () => {
        const judge = fixture('judge') as {
            cases: { items: unknown[] }[];
        };

        expect(judge.cases.length).toBeGreaterThan(0);

        for (const entry of judge.cases) {
            for (const item of entry.items) {
                expect(RoomItem.safeParse(item).error).toBeUndefined();
            }
        }
    });
});

describe('what a state never carries', () => {
    it('refuses a state that carries an account id or an address', () => {
        const [first, ...rest] = lobby.players;

        for (const extra of [
            { user_id: 7 },
            { account_id: 7 },
            { email: 'resad@example.com' },
            { ip: '10.0.0.7' },
        ]) {
            expect(
                RoomState.safeParse({
                    ...lobby,
                    players: [{ ...first, ...extra }, ...rest],
                }).success,
            ).toBe(false);
            expect(
                RoomState.safeParse({ ...lobby, me: { ...lobby.me, ...extra } })
                    .success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...lobby,
                    room: { ...lobby.room, ...extra },
                }).success,
            ).toBe(false);
            expect(RoomState.safeParse({ ...lobby, ...extra }).success).toBe(
                false,
            );
        }
    });

    it('refuses a player known by anything but a row id', () => {
        const [first, ...rest] = lobby.players;

        expect(
            RoomState.safeParse({
                ...lobby,
                players: [{ ...first, id: 7 }, ...rest],
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({ ...lobby, me: { ...lobby.me, player_id: 7 } })
                .success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...lobby,
                ranking: [{ player_id: 7, points: 20, place: 1 }],
            }).success,
        ).toBe(false);
    });

    it('refuses a solution outside the reveal', () => {
        const reveal = fixture('state.reveal') as State;
        const revealed = (reveal.round as Extract<Round, { phase: 'reveal' }>)
            .reveal;

        for (const name of ['state.countdown', 'state.study', 'state.answer']) {
            const state = fixture(name) as State;
            const round = state.round as Round;

            // The reveal in an earlier phase, whole or in parts.
            for (const leak of [
                { reveal: revealed },
                { solution: revealed.solution },
                { reveal: { ...revealed, mine: null, results: [] } },
                { closes_ms: round.bell_ms, next_ms: round.bell_ms + 3000 },
            ]) {
                expect(
                    RoomState.safeParse({
                        ...state,
                        round: { ...round, ...leak },
                    }).success,
                    `${name} with ${Object.keys(leak).join(', ')}`,
                ).toBe(false);
            }

            // Nor beside the round, the game or a ranking row.
            expect(
                RoomState.safeParse({ ...state, solution: revealed.solution })
                    .success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...state,
                    game: { ...state.game, solution: revealed.solution },
                }).success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...state,
                    ranking: state.ranking.map((row) => ({
                        ...row,
                        correct: true,
                    })),
                }).success,
            ).toBe(false);
        }

        // A result or one's own answer never rides on the question.
        const answer = fixture('state.answer') as State;
        const asked = answer.round as Extract<Round, { phase: 'answer' }>;

        for (const leak of [
            { correct: true },
            { points: 19 },
            { mine: revealed.mine },
        ]) {
            expect(
                RoomState.safeParse({
                    ...answer,
                    round: { ...asked, ask: { ...asked.ask, ...leak } },
                }).success,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...answer,
                    round: {
                        ...asked,
                        ask: {
                            ...asked.ask,
                            items: asked.ask.items.map((item) => ({
                                ...item,
                                ...leak,
                            })),
                        },
                    },
                }).success,
            ).toBe(false);
        }
    });

    it('serves the material and the question each in its own phase', () => {
        const study = fixture('state.study') as State;
        const answer = fixture('state.answer') as State;
        const studied = study.round as Extract<Round, { phase: 'study' }>;
        const asked = answer.round as Extract<Round, { phase: 'answer' }>;
        const countdown = fixture('state.countdown') as State;

        // The material again in the answer phase; the question while studying or before.
        expect(
            RoomState.safeParse({
                ...answer,
                round: { ...asked, study: studied.study },
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...study,
                round: { ...studied, ask: asked.ask },
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...countdown,
                round: { ...countdown.round, study: studied.study },
            }).success,
        ).toBe(false);
        // Nobody has answered before the question.
        expect(
            RoomState.safeParse({
                ...study,
                round: { ...studied, answered: true, answered_count: 1 },
            }).success,
        ).toBe(false);
    });

    it('shows a logic round with its question, the row apart from the options', () => {
        for (const name of ['state.answer.sequence', 'state.answer.odd']) {
            const state = fixture(name) as State;
            const asked = state.round as Extract<Round, { phase: 'answer' }>;

            // Nothing is studied: the question opens with the round.
            expect(asked.study, name).toBeNull();
            expect(asked.answer_from_ms, name).toBe(asked.opens_ms);
            expect(asked.ask.shape, name).toBe('choice');
            expect(asked.ask.pick, name).toBe(1);

            // A question without its shown row, or a shown item that carries more, is refused.
            const { shown: _shown, ...withoutShown } = asked.ask;
            expect(
                RoomState.safeParse({
                    ...state,
                    round: { ...asked, ask: withoutShown },
                }).success,
                name,
            ).toBe(false);
            expect(
                RoomState.safeParse({
                    ...state,
                    round: {
                        ...asked,
                        ask: {
                            ...asked.ask,
                            shown: [{ ...asked.ask.items[0], correct: true }],
                        },
                    },
                }).success,
                name,
            ).toBe(false);
        }

        const sequence = (fixture('state.answer.sequence') as State)
            .round as Extract<Round, { phase: 'answer' }>;
        const odd = (fixture('state.answer.odd') as State).round as Extract<
            Round,
            { phase: 'answer' }
        >;

        expect(sequence.ask.shown.length).toBeGreaterThan(0);
        expect(sequence.ask.items).toHaveLength(4);
        expect(odd.ask.shown).toEqual([]);
    });

    it('says the choice of the next game, played only once it is the game over', () => {
        const over = fixture('state.over') as State;
        const chosen = fixture('state.chosen') as State;

        expect(lobby.v).toBe(ROOM_STATE_VERSION);
        expect(lobby.setup).toEqual({
            kind: 'race',
            mechanic: 'recall_order',
            difficulty: 2,
            rounds: 10,
            played: false,
        });
        expect(over.setup?.played).toBe(true);
        expect(RoomState.safeParse(chosen).success).toBe(true);
        expect(chosen.game?.phase).toBe('over');
        expect(chosen.setup).toEqual({
            kind: 'race',
            mechanic: 'anagram',
            difficulty: 2,
            rounds: 5,
            played: false,
        });

        // A strict object: nothing of a person, no other key, no other version.
        for (const setup of [
            { ...lobby.setup, player_id: lobby.me.player_id },
            { ...lobby.setup, name: 'Rəşad' },
            { ...lobby.setup, difficulty: 5 },
            { ...lobby.setup, mechanic: 'find' },
            { mechanic: 'recall_order', difficulty: 2, rounds: 10 },
            { ...lobby.setup, kind: 'board' },
            { ...lobby.setup, mechanic: 'draughts' },
        ]) {
            expect(RoomState.safeParse({ ...lobby, setup }).success).toBe(
                false,
            );
        }

        expect(RoomState.safeParse({ ...lobby, setup: null }).success).toBe(
            true,
        );
        expect(RoomState.safeParse({ ...lobby, v: 1 }).success).toBe(false);
    });

    it('holds a round only while a game is live', () => {
        const answer = fixture('state.answer') as State;
        const over = fixture('state.over') as State;

        expect(RoomState.safeParse({ ...answer, game: null }).success).toBe(
            false,
        );
        expect(
            RoomState.safeParse({ ...over, round: answer.round }).success,
        ).toBe(false);
        expect(RoomState.safeParse({ ...answer, round: null }).success).toBe(
            false,
        );
    });
});

describe('an answer and its receipt', () => {
    const answer = fixture('answer') as z.infer<typeof RoomAnswer>;

    it('names no player and carries no time of the device', () => {
        for (const extra of [
            { player_id: lobby.me.player_id },
            { user_id: 7 },
            { elapsed_ms: 1200 },
            { sent_ms: 1795534226000 },
            { correct: true },
        ]) {
            expect(RoomAnswer.safeParse({ ...answer, ...extra }).success).toBe(
                false,
            );
        }
    });

    it('says what was done and never whether it was right', () => {
        expect(
            RoomAnswerReceipt.safeParse({
                status: 'accepted',
                revision: 5,
                correct: true,
            }).success,
        ).toBe(false);
        expect(
            RoomAnswerReceipt.safeParse({ status: 'wrong', revision: 5 })
                .success,
        ).toBe(false);
    });
});

describe('taunts', () => {
    const answer = fixture('state.answer') as State;
    const taunted = fixture('state.taunted') as State;
    const screen = fixture('state.screen-taunted') as State;
    const over = fixture('state.over') as State;

    it("carries a live game's taunts and nothing else any", () => {
        expect(taunted.taunts?.received).toHaveLength(1);
        expect(screen.taunts?.shown).toHaveLength(2);
        expect(answer.taunts?.shown).toBeNull();

        for (const state of [lobby, over]) {
            expect(
                RoomState.safeParse({ ...state, taunts: answer.taunts })
                    .success,
            ).toBe(false);
        }

        expect(RoomState.safeParse({ ...answer, taunts: null }).success).toBe(
            false,
        );
    });

    it("never names a received taunt's target, never sends a phrase, a name or a verdict", () => {
        const received = taunted.taunts!.received[0];
        const shown = screen.taunts!.shown![0];

        for (const taunts of [
            {
                ...taunted.taunts,
                received: [{ ...received, to: shown.to }],
            },
            {
                ...taunted.taunts,
                received: [{ ...received, text: 'Bir az tez ol!' }],
            },
            { ...taunted.taunts, received: [{ ...received, name: 'Aygün' }] },
            {
                ...taunted.taunts,
                received: [{ ...received, taunt: 'Bir az tez ol!' }],
            },
            { ...screen.taunts, shown: [{ ...shown, correct: true }] },
            { ...answer.taunts, balance: -1 },
            { ...answer.taunts, points: 20 },
            {
                ...taunted.taunts,
                received: [received, received, received, received],
            },
        ]) {
            expect(RoomState.safeParse({ ...answer, taunts }).success).toBe(
                false,
            );
        }
    });

    it('lists the cost of a close only in its reveal', () => {
        const reveal = fixture('state.reveal') as State;
        const revealed = (reveal.round as Extract<Round, { phase: 'reveal' }>)
            .reveal;
        const asked = answer.round as Extract<Round, { phase: 'answer' }>;

        expect(revealed.spent).toEqual([
            { player_id: '01kaqrx2ab3c4d5e6f7g8h9jkm', count: 1 },
        ]);
        expect(
            RoomState.safeParse({
                ...answer,
                round: { ...asked, spent: revealed.spent },
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...reveal,
                round: {
                    ...reveal.round,
                    reveal: {
                        ...revealed,
                        spent: [{ ...revealed.spent[0], count: 0 }],
                    },
                },
            }).success,
        ).toBe(false);
    });

    it('posts a key of the list and nothing of a person', () => {
        const taunt = fixture('taunt') as z.infer<typeof RoomTaunt>;

        expect(
            RoomTaunt.safeParse({ ...taunt, text: 'Bir az tez ol!' }).success,
        ).toBe(false);
        expect(
            RoomTaunt.safeParse({ ...taunt, from: taunt.target_id }).success,
        ).toBe(false);
        expect(
            RoomTauntReceipt.safeParse({ status: 'refused', revision: 5 })
                .success,
        ).toBe(false);
    });
});

describe('the live voice (C3)', () => {
    const voice = fixture('state.voice') as State;
    const off = fixture('state.voice.off') as State;
    const quiet = (state: State): State => ({
        ...state,
        players: state.players.map((player) => ({ ...player, voice: null })),
    });

    it("reads every state at v 6, with the voice and each player's standing", () => {
        expect(ROOM_STATE_VERSION).toBe(6);

        for (const [path, value] of payloads) {
            if (!path.includes('/state.')) {
                continue;
            }

            const state = value as State;

            expect(state.v, path).toBe(6);
            expect('voice' in state, path).toBe(true);
            expect(
                state.players.every((player) => 'voice' in player),
                path,
            ).toBe(true);
        }

        expect(voice.voice).toEqual({ on: true, agreed: true });
        expect(voice.players.map((player) => player.voice)).toEqual([
            'open',
            'open',
            'muted',
            null,
            'waiting',
        ]);
        expect(off.voice).toEqual({ on: false, agreed: false });
        expect(off.players.every((player) => player.voice === null)).toBe(true);
    });

    it('refuses a key of voice in a closed room', () => {
        const closed = {
            ...quiet(voice),
            room: { ...voice.room, status: 'closed' as const, code: null },
            setup: null,
        };

        expect(RoomState.safeParse(closed).success).toBe(false);
        expect(RoomState.safeParse({ ...closed, voice: null }).success).toBe(
            true,
        );
    });

    it('gives a standing exactly while the voice is on, to the host and the present players', () => {
        expect(
            RoomState.safeParse({
                ...voice,
                voice: { on: false, agreed: true },
            }).success,
        ).toBe(false);
        expect(RoomState.safeParse({ ...voice, voice: null }).success).toBe(
            false,
        );
        expect(RoomState.safeParse(quiet(voice)).success).toBe(false);
        expect(
            RoomState.safeParse({
                ...voice,
                players: voice.players.map((player, index) =>
                    index === 3 ? { ...player, voice: 'open' } : player,
                ),
            }).success,
        ).toBe(false);
    });

    it('says nothing of who is in the voice, whose microphone is open or who speaks', () => {
        expect(
            RoomState.safeParse({
                ...voice,
                voice: { ...voice.voice, speaking: [voice.players[0].id] },
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...voice,
                players: voice.players.map((player) => ({
                    ...player,
                    microphone: true,
                })),
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...voice,
                players: voice.players.map((player) =>
                    player.voice === 'open'
                        ? { ...player, voice: 'speaking' }
                        : player,
                ),
            }).success,
        ).toBe(false);
        expect(RoomState.safeParse({ ...voice, v: 4 }).success).toBe(false);
    });
});

describe('a board game (D3)', () => {
    type Match = NonNullable<State['match']>;
    const play = fixture('state.match.play') as State;
    const watch = fixture('state.match.watch') as State;
    const offer = fixture('state.match.offer') as State;
    const over = fixture('state.match.over') as State;
    const countdown = fixture('state.match.countdown') as State;
    const matchOf = (state: State): Match => state.match as Match;
    const withMatch = (state: State, match: Record<string, unknown>) => ({
        ...state,
        match: { ...matchOf(state), ...match },
    });

    it('carries the match of a board game, live or over, and of nothing else', () => {
        for (const [path, value] of payloads) {
            if (!path.includes('/state.')) {
                continue;
            }

            const state = value as State;
            const board = state.game?.kind === 'board';

            expect(state.match !== null, path).toBe(board);
            expect(state.round === null || !board, path).toBe(true);

            if (board) {
                expect(matchOf(state).phase === 'over', path).toBe(
                    state.game?.phase === 'over',
                );
            }
        }

        expect(RoomState.safeParse({ ...play, match: null }).success).toBe(
            false,
        );
        expect(
            RoomState.safeParse({
                ...(fixture('state.answer') as State),
                match: play.match,
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...over,
                game: { ...over.game, phase: 'live' },
                taunts: play.taunts,
            }).success,
        ).toBe(false);
        expect(
            RoomState.safeParse({
                ...play,
                game: { ...play.game, kind: 'race' },
            }).success,
        ).toBe(false);
    });

    it('offers the legal paths to the seat to move alone, the same board to everyone', () => {
        expect(matchOf(play).legal).toEqual({
            capture: true,
            paths: [['d4', 'f6']],
        });
        expect(matchOf(watch).legal).toBeNull();
        expect({ ...matchOf(watch), legal: null }).toEqual({
            ...matchOf(play),
            legal: null,
        });
        expect([matchOf(offer).offer, matchOf(offer).can_offer]).toEqual([
            'black',
            false,
        ]);
        expect(matchOf(countdown).phase).toBe('countdown');
        expect(matchOf(over).result?.ending).toBe('resigned');
    });

    it('refuses a key of a match in a phase that has none, and a square that is not one', () => {
        for (const [state, match] of [
            [play, { result: matchOf(over).result }],
            [over, { legal: matchOf(play).legal }],
            [over, { turn: 'white' }],
            [countdown, { offer: 'black' }],
            [countdown, { can_offer: true }],
            [countdown, { moves: ['c3-d4'] }],
            [play, { legal: { capture: false, paths: [] } }],
            [play, { legal: { capture: false, paths: [['d4', 'd5']] } }],
            [
                play,
                { position: [{ square: 'a2', color: 'white', king: false }] },
            ],
            [play, { moves: ['c3d4'] }],
            [play, { sides: [matchOf(play).sides[0]] }],
            [play, { player_id: play.me.player_id }],
        ] as const) {
            expect(
                RoomState.safeParse(withMatch(state, match)).success,
                JSON.stringify(match),
            ).toBe(false);
        }
    });

    it('ends a match by each ending, its places by the outcome', () => {
        const result = matchOf(over).result;

        if (result === null) {
            throw new Error('The match is over.');
        }

        const ended = (
            outcome: string,
            ending: string,
            places: [number | null, number | null],
        ) =>
            withMatch(over, {
                result: {
                    ...result,
                    outcome,
                    ending,
                    spent: [],
                    standing: result.standing.map((row, index) => ({
                        ...row,
                        place: places[index],
                    })),
                },
            });

        for (const [ending, outcome, places] of [
            ['no_moves', 'white', [1, 2]],
            ['resigned', 'black', [1, 2]],
            ['left', 'white', [1, 2]],
            ['time', 'black', [1, 2]],
            ['agreed', 'draw', [1, 1]],
            ['repetition', 'draw', [1, 1]],
            ['three_kings', 'draw', [1, 1]],
            ['main_road', 'draw', [1, 1]],
            ['balance', 'draw', [1, 1]],
            ['kings_only', 'draw', [1, 1]],
            ['stopped', 'none', [null, null]],
        ] as const) {
            const wrong: [number | null, number | null] =
                places[0] === 1 && places[1] === 2 ? [2, 1] : [1, 2];

            expect(
                RoomState.safeParse(ended(outcome, ending, [...places]))
                    .success,
                ending,
            ).toBe(true);
            expect(
                RoomState.safeParse(ended(outcome, ending, wrong)).success,
                ending,
            ).toBe(false);
            expect(
                RoomState.safeParse(
                    ended(outcome === 'draw' ? 'white' : 'draw', ending, [
                        ...places,
                    ]),
                ).success,
                ending,
            ).toBe(false);
        }
    });

    it('posts an act of a seat in its game and nothing of a person, never a leave', () => {
        const act = fixture('act') as z.infer<typeof RoomAct>;
        const named = { event_id: act.event_id, game_id: act.game_id };

        expect(RoomAct.safeParse(act).success).toBe(true);
        expect(act.game_id).toBe(play.game?.id);
        expect(RoomAct.safeParse({ ...named, kind: 'resign' }).success).toBe(
            true,
        );
        expect(
            RoomAct.safeParse({ ...named, kind: 'offer', ply: 3 }).success,
        ).toBe(true);

        for (const wrong of [
            { ...act, player_id: play.me.player_id },
            { ...act, path: ['d4'] },
            { ...act, path: ['d4', 'e4'] },
            { event_id: act.event_id, kind: 'resign' },
            { ...named, kind: 'leave', ply: 2 },
            { ...named, kind: 'resign', ply: 2 },
            { ...named, kind: 'offer' },
            { ...named, kind: 'decline', ply: 2, path: ['d4', 'f6'] },
            { ...act, acted_ms: 1 },
        ]) {
            expect(
                RoomAct.safeParse(wrong).success,
                JSON.stringify(wrong),
            ).toBe(false);
        }

        expect(
            RoomActReceipt.safeParse({ status: 'answered', revision: 1 })
                .success,
        ).toBe(false);
    });
});

describe('room.changed', () => {
    it('carries the revision and nothing else', () => {
        expect(RoomChangedEvent.safeParse({ v: 1, revision: 4 }).success).toBe(
            true,
        );
        expect(
            RoomChangedEvent.safeParse({ v: 1, revision: 4, name: 'Aygün' })
                .success,
        ).toBe(false);
    });
});
