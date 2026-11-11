import { describe, expect, it } from 'vite-plus/test';
import type { z } from 'zod';
import {
    ClientAck,
    ClientControl,
    ClientHello,
    ClientHit,
    ClientPing,
    ClientState,
    ClientStudent,
    GameState,
    GameStatus,
    Pairing,
    Round,
    SessionStatusEvent,
    SyncRequest,
    SyncResponse,
} from './contract';

/*
 * Every fixture in tests/Fixtures/contract parses with its strict schema;
 * Pest round-trips the same files through the PHP DTOs
 * (tests/Unit/Play/ContractTest.php). The prefix of a file name names its
 * schema.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/contract/*.json',
    { eager: true, import: 'default' },
);

const schemas: Record<string, z.ZodType> = {
    round: Round,
    state: GameState,
    'sync-request': SyncRequest,
    'sync-response': SyncResponse,
    'game-status': GameStatus,
};

function schemaOf(path: string): z.ZodType | undefined {
    const name = path.split('/').pop() ?? '';

    return schemas[name.slice(0, name.indexOf('.'))];
}

const contractFixtures = Object.entries(fixtures).filter(
    ([path]) => !path.endsWith('/judge.json'),
);

describe('contract fixtures', () => {
    it('finds the fixtures', () => {
        expect(contractFixtures.length).toBeGreaterThanOrEqual(5);
    });

    it.each(contractFixtures)('%s names a known schema', (path) => {
        expect(schemaOf(path)).toBeDefined();
    });

    it.each(contractFixtures)('%s parses strictly', (path, fixture) => {
        const result = schemaOf(path)?.safeParse(fixture);

        expect(result?.error).toBeUndefined();
        expect(result?.data).toEqual(fixture);
    });
});

describe('strict schemas', () => {
    const round =
        fixtures['../../../tests/Fixtures/contract/round.find-letter.json'];

    it('has the find-letter fixture to break', () => {
        expect(Round.safeParse(round).success).toBe(true);
    });

    it('rejects an extra key', () => {
        expect(
            Round.safeParse({ ...(round as object), extra: 1 }).success,
        ).toBe(false);
    });

    it('rejects a missing key', () => {
        const { effect: _effect, ...rest } = round as Record<string, unknown>;

        expect(Round.safeParse(rest).success).toBe(false);
    });

    it('rejects an extra key deep inside', () => {
        const parsed = Round.parse(round);
        const broken = {
            ...parsed,
            items: [
                { ...parsed.items[0], colour: 'red' },
                ...parsed.items.slice(1),
            ],
        };

        expect(Round.safeParse(broken).success).toBe(false);
    });

    it('rejects a value outside a closed list', () => {
        expect(
            Round.safeParse({ ...(round as object), unit: 'phrase' }).success,
        ).toBe(false);
    });

    it('rejects a prompt without count, and a count outside 1–10 (R5)', () => {
        const parsed = Round.parse(round);
        const { count: _count, ...prompt } = parsed.prompt;

        expect(Round.safeParse({ ...parsed, prompt }).success).toBe(false);

        for (const count of [0, 11, 2.5]) {
            expect(
                Round.safeParse({
                    ...parsed,
                    prompt: { ...parsed.prompt, count },
                }).success,
            ).toBe(false);
        }
    });
});

describe('picture and counting rounds (R5)', () => {
    const fixture = (name: string) =>
        Round.parse(fixtures[`../../../tests/Fixtures/contract/${name}`]);

    it('gives every item of a picture round its picture and the prompt none', () => {
        const round = fixture('round.find-picture.json');

        expect(round.unit).toBe('picture');
        expect(round.items.every((item) => item.image !== null)).toBe(true);
        expect(round.prompt.image).toBeNull();
        expect(round.prompt.count).toBeNull();
        expect(round.prompt.show).toBe('pişik');
    });

    it("shows a count round's picture N times and never the number", () => {
        const round = fixture('round.count.json');
        const answer = round.items.find(
            (item) => item.id === round.solution.item_ids[0],
        );

        expect(round.mechanic).toBe('count');
        expect(round.prompt.image).not.toBeNull();
        expect(round.prompt.show).toBeNull();
        expect(answer?.text).toBe(String(round.prompt.count));
        expect(round.items.every((item) => item.image === null)).toBe(true);
    });
});

/*
 * The client events of protocol v1 (§4): what the phone and the board
 * whisper. Each schema takes its valid payloads and refuses the malformed
 * ones a receiver must drop — a wrong version, an extra key (a student's
 * name has no place in an event), a missing key, a value outside its list.
 */
const EVENT_ID = '01JC8ZA0000000000000000001';

const clientEvents: {
    name: string;
    schema: z.ZodType;
    valid: Record<string, unknown>[];
    malformed: unknown[];
}[] = [
    {
        name: 'client-hello',
        schema: ClientHello,
        valid: [{ v: 1, device_id: '01JC8ZB0000000000000000001' }],
        malformed: [
            { v: 1, device_id: '' },
            { v: 1, device_id: 'x'.repeat(65) },
            { v: 1 },
        ],
    },
    {
        name: 'client-hit',
        schema: ClientHit,
        valid: [
            {
                v: 1,
                event_id: EVENT_ID,
                round_id: '01JC8ZC0000000000000000001',
                item_id: 'i1',
                client_at: '2026-11-09T09:00:00.000Z',
            },
        ],
        malformed: [
            {
                v: 1,
                event_id: 'tap-1',
                round_id: 'r',
                item_id: 'i',
                client_at: '2026-11-09T09:00:00.000Z',
            },
            {
                v: 1,
                event_id: EVENT_ID,
                round_id: 'r',
                item_id: '',
                client_at: '2026-11-09T09:00:00.000Z',
            },
            {
                v: 1,
                event_id: EVENT_ID,
                round_id: 'r',
                item_id: 'i',
                client_at: 'yesterday',
            },
            {
                v: 1,
                event_id: EVENT_ID,
                round_id: 'r',
                item_id: 'i',
                client_at: null,
            },
        ],
    },
    {
        name: 'client-student',
        schema: ClientStudent,
        valid: [
            { v: 1, student_id: '01JC8ZD0000000000000000001' },
            { v: 1, student_id: null },
        ],
        malformed: [
            { v: 1, student_id: 7 },
            { v: 1, student_id: '' },
            { v: 1 },
        ],
    },
    {
        name: 'client-control',
        schema: ClientControl,
        valid: ['pause', 'resume', 'skip', 'end', 'pairing'].map((action) => ({
            v: 1,
            action,
        })),
        malformed: [
            { v: 1, action: 'restart' },
            { v: 1, action: null },
        ],
    },
    {
        name: 'client-ping',
        schema: ClientPing,
        valid: [{ v: 1, t: 1_794_214_800_000 }],
        malformed: [{ v: 1, t: 'now' }, { v: 1, t: Number.NaN }, { v: 1 }],
    },
    {
        name: 'client-state',
        schema: ClientState,
        valid: [
            {
                v: 1,
                board_epoch: 5,
                status: 'live',
                round_id: '01JC8ZC0000000000000000001',
                round_index: 0,
                student_id: '01JC8ZD0000000000000000001',
                hit_item_ids: ['i1'],
                misses: 2,
                last_points: 3,
            },
            {
                v: 1,
                board_epoch: 0,
                status: 'finished',
                round_id: null,
                round_index: null,
                student_id: null,
                hit_item_ids: [],
                misses: 0,
                last_points: null,
            },
        ],
        malformed: [
            {
                v: 1,
                board_epoch: -1,
                status: 'live',
                round_id: null,
                round_index: null,
                student_id: null,
                hit_item_ids: [],
                misses: 0,
                last_points: null,
            },
            {
                v: 1,
                board_epoch: 1,
                status: 'started',
                round_id: null,
                round_index: null,
                student_id: null,
                hit_item_ids: [],
                misses: 0,
                last_points: null,
            },
            {
                v: 1,
                board_epoch: 1,
                status: 'live',
                round_id: null,
                round_index: 1.5,
                student_id: null,
                hit_item_ids: [],
                misses: 0,
                last_points: null,
            },
        ],
    },
    {
        name: 'client-ack',
        schema: ClientAck,
        valid: ['correct', 'miss', 'duplicate', 'stale'].map((status) => ({
            v: 1,
            event_id: EVENT_ID,
            status,
        })),
        malformed: [
            // `skipped` is the server's word for a skip, never an ack.
            { v: 1, event_id: EVENT_ID, status: 'skipped' },
            { v: 1, event_id: 'tap-1', status: 'correct' },
        ],
    },
    {
        name: 'session.status',
        schema: SessionStatusEvent,
        valid: ['live', 'paused', 'finished', 'abandoned'].map((status) => ({
            v: 1,
            status,
        })),
        malformed: [{ v: 1, status: 'ended' }],
    },
];

describe('client events (protocol v1)', () => {
    describe.each(clientEvents)('$name', ({ schema, valid, malformed }) => {
        it.each(valid)('takes %o', (payload) => {
            expect(schema.safeParse(payload).data).toEqual(payload);
        });

        it.each(malformed)('drops %o', (payload) => {
            expect(schema.safeParse(payload).success).toBe(false);
        });

        it('drops another version, an extra key and a non-object', () => {
            const payload = valid[0];
            const withoutV = Object.fromEntries(
                Object.entries(payload).filter(([key]) => key !== 'v'),
            );

            expect(schema.safeParse({ ...payload, v: 2 }).success).toBe(false);
            expect(schema.safeParse(withoutV).success).toBe(false);
            expect(
                schema.safeParse({ ...payload, display_name: 'Aysel' }).success,
            ).toBe(false);
            expect(schema.safeParse(null).success).toBe(false);
            expect(schema.safeParse('client-hit').success).toBe(false);
        });
    });
});

describe('pairing', () => {
    it('takes a 6-digit code and refuses anything else', () => {
        const pairing = {
            code: '482913',
            expires_at: '2026-11-09T09:10:00Z',
            url: 'http://elifba.test/pult/482913',
            entry_url: 'http://elifba.test/pult',
        };

        expect(Pairing.safeParse(pairing).success).toBe(true);
        expect(Pairing.safeParse({ ...pairing, code: '48291' }).success).toBe(
            false,
        );
        expect(Pairing.safeParse({ ...pairing, code: '482 913' }).success).toBe(
            false,
        );
    });
});
