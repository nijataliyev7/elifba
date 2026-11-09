import { describe, expect, it, vi } from 'vite-plus/test';
import type { BoardEvent, SyncRequest, SyncResponse } from './contract';
import type { OutboxOptions, SendResult, StorageLike, Timers } from './outbox';
import { defaultBackoff, Outbox } from './outbox';
import { ulid } from './ulid';

/*
 * The board's outbox (docs/architecture.md §4, "Reconnects"): ordered,
 * kept across a reload, capped, sent in batches of one epoch, retried with
 * backoff, stopped by a 409, and never blocked by one bad event.
 */

const KEY = 'elifba.board.game-1.outbox';

function memoryStorage(): StorageLike & { data: Map<string, string> } {
    const data = new Map<string, string>();

    return {
        data,
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => {
            data.set(key, value);
        },
        removeItem: (key) => {
            data.delete(key);
        },
    };
}

/** Timers the test fires by hand. */
function manualTimers(): Timers & {
    pending: { handle: number; ms: number; callback: () => void }[];
    fire: () => void;
} {
    const pending: { handle: number; ms: number; callback: () => void }[] = [];
    let next = 1;

    return {
        pending,
        set: (callback, ms) => {
            const handle = next++;

            pending.push({ handle, ms, callback });

            return handle;
        },
        clear: (handle) => {
            const at = pending.findIndex((timer) => timer.handle === handle);

            if (at >= 0) {
                pending.splice(at, 1);
            }
        },
        fire: () => {
            pending.shift()?.callback();
        },
    };
}

function eventId(n: number): string {
    return `01JC8ZA${String(n).padStart(19, '0')}`;
}

function hit(n: number, extra: Partial<BoardEvent> = {}): BoardEvent {
    return {
        type: 'hit',
        event_id: eventId(n),
        round_id: 'round-1',
        item_id: 'a',
        source: 'board',
        student_id: null,
        client_at: '2026-11-09T09:00:00.000Z',
        ...extra,
    } as BoardEvent;
}

function answer(
    request: SyncRequest,
    points: number | null = null,
): SyncResponse {
    return {
        results: request.events.map((event) => ({
            event_id: event.event_id,
            status: event.type === 'hit' ? 'correct' : 'skipped',
            round_solved: points !== null,
            points,
        })),
        game: { status: 'live', board_epoch: request.board_epoch },
    };
}

function server(
    respond: (request: SyncRequest) => SendResult = (request) => ({
        kind: 'ok',
        response: answer(request),
    }),
) {
    return vi.fn(async (request: SyncRequest) => respond(request));
}

function outbox(overrides: Partial<OutboxOptions> = {}) {
    const storage = memoryStorage();
    const timers = manualTimers();
    const send = server();
    const box = new Outbox({ key: KEY, storage, send, timers, ...overrides });

    return { box, storage, timers, send };
}

function sentIds(send: ReturnType<typeof server>): string[][] {
    return send.mock.calls.map(([request]) =>
        request.events.map((event) => event.event_id),
    );
}

describe('queuing', () => {
    it('sends events in the order they were made and empties the stored queue', async () => {
        const { box, storage, send } = outbox();

        box.push(3, hit(1));
        box.push(3, hit(2));
        box.push(3, hit(3));

        expect(await box.flush()).toBe('flushed');
        expect(sentIds(send).flat()).toEqual([
            eventId(1),
            eventId(2),
            eventId(3),
        ]);
        expect(send.mock.calls[0][0].board_epoch).toBe(3);
        expect(box.size).toBe(0);
        expect(storage.data.has(KEY)).toBe(false);
    });

    it('keeps the queue in sessionStorage, so a reload sends it', async () => {
        const storage = memoryStorage();
        const offline = server(() => ({ kind: 'retry' }));
        const first = new Outbox({
            key: KEY,
            storage,
            send: offline,
            timers: manualTimers(),
        });

        first.push(2, hit(1));
        first.push(2, hit(2, { item_id: 'b' }));
        await first.flush();
        first.dispose();

        expect(JSON.parse(storage.data.get(KEY) ?? '[]')).toHaveLength(2);

        const send = server();
        const reloaded = new Outbox({
            key: KEY,
            storage,
            send,
            timers: manualTimers(),
        });

        expect(reloaded.size).toBe(2);
        expect(await reloaded.flush()).toBe('flushed');
        expect(send.mock.calls[0][0]).toEqual({
            board_epoch: 2,
            events: [hit(1), hit(2, { item_id: 'b' })],
        });
    });

    it('drops an event that fails its schema before it is queued', async () => {
        const { box, send } = outbox();

        expect(box.push(1, hit(1, { event_id: 'not-a-ulid' }))).toBe(false);
        expect(box.push(1, { ...hit(2), type: 'dance' })).toBe(false);
        expect(box.push(1, { ...hit(3), extra: 'key' })).toBe(false);
        expect(box.push(-1, hit(4))).toBe(false);
        expect(box.push(1, hit(5))).toBe(true);

        await box.flush();

        expect(sentIds(send)).toEqual([[eventId(5)]]);
    });

    it('skips broken stored entries and keeps the rest in order', () => {
        const storage = memoryStorage();

        storage.setItem(
            KEY,
            JSON.stringify([
                { epoch: 1, event: hit(1) },
                { epoch: 1, event: { type: 'hit' } },
                'junk',
                { epoch: 1, event: hit(2) },
            ]),
        );

        const box = new Outbox({ key: KEY, storage, send: server() });

        expect(box.entries().map((entry) => entry.event.event_id)).toEqual([
            eventId(1),
            eventId(2),
        ]);

        storage.setItem(KEY, '{not json');

        expect(new Outbox({ key: KEY, storage, send: server() }).size).toBe(0);
    });

    it('holds at most 500 events and refuses newer ones, keeping the older', async () => {
        const offline = server(() => ({ kind: 'retry' }));
        const { box } = outbox({ send: offline });

        for (let n = 1; n <= 500; n++) {
            expect(box.push(1, hit(n))).toBe(true);
        }

        expect(box.push(1, hit(501))).toBe(false);
        expect(box.size).toBe(500);
        expect(box.entries().at(-1)?.event.event_id).toBe(eventId(500));
    });
});

describe('batches', () => {
    it('sends at most 50 events per request', async () => {
        const storage = memoryStorage();

        storage.setItem(
            KEY,
            JSON.stringify(
                Array.from({ length: 120 }, (_, i) => ({
                    epoch: 4,
                    event: hit(i + 1),
                })),
            ),
        );

        const send = server();
        const box = new Outbox({ key: KEY, storage, send });

        expect(await box.flush()).toBe('flushed');
        expect(sentIds(send).map((ids) => ids.length)).toEqual([50, 50, 20]);
        expect(sentIds(send).flat()).toEqual(
            Array.from({ length: 120 }, (_, i) => eventId(i + 1)),
        );
        expect(box.size).toBe(0);
    });

    it('never mixes two epochs in one request', async () => {
        const storage = memoryStorage();

        storage.setItem(
            KEY,
            JSON.stringify([
                { epoch: 1, event: hit(1) },
                { epoch: 1, event: hit(2) },
                { epoch: 2, event: hit(3) },
            ]),
        );

        const send = server();
        const box = new Outbox({ key: KEY, storage, send });

        await box.flush();

        expect(
            send.mock.calls.map(([request]) => [
                request.board_epoch,
                request.events.length,
            ]),
        ).toEqual([
            [1, 2],
            [2, 1],
        ]);
    });

    it('reports every answer with its event and the game status', async () => {
        const onResults = vi.fn();
        const send = server((request) => ({
            kind: 'ok',
            response: answer(request, 3),
        }));
        const { box } = outbox({ send, onResults });

        box.push(1, hit(1));
        await box.flush();

        expect(onResults).toHaveBeenCalledWith(
            [
                {
                    entry: { epoch: 1, event: hit(1) },
                    result: {
                        event_id: eventId(1),
                        status: 'correct',
                        round_solved: true,
                        points: 3,
                    },
                },
            ],
            { status: 'live', board_epoch: 1 },
        );
    });

    it('sends events pushed while a request is in flight in the same run', async () => {
        let release: () => void = () => {};
        const send = vi.fn(
            async (request: SyncRequest): Promise<SendResult> => {
                if (request.events[0].event_id === eventId(1)) {
                    await new Promise<void>((resolve) => {
                        release = resolve;
                    });
                }

                return { kind: 'ok', response: answer(request) };
            },
        );
        const { box } = outbox({ send });

        box.push(1, hit(1));
        const running = box.flush();

        box.push(1, hit(2));
        release();

        expect(await running).toBe('flushed');
        expect(sentIds(send as never)).toEqual([[eventId(1)], [eventId(2)]]);
    });
});

describe('failures', () => {
    it('retries with growing backoff while offline, and keeps the events', async () => {
        let online = false;
        const send = server((request) =>
            online
                ? { kind: 'ok', response: answer(request) }
                : { kind: 'retry' },
        );
        const changes: boolean[] = [];
        const { box, timers } = outbox({
            send,
            onChange: (state) => changes.push(state.retrying),
        });

        box.push(1, hit(1));
        expect(await box.flush()).toBe('retry');
        expect(box.state).toEqual({ size: 1, retrying: true, stopped: false });
        expect(timers.pending.map((timer) => timer.ms)).toEqual([1000]);

        // A new event while waiting does not cut the wait short.
        box.push(1, hit(2));
        expect(send).toHaveBeenCalledTimes(1);

        timers.fire();
        await box.flush();
        expect(timers.pending.map((timer) => timer.ms)).toEqual([2000]);

        online = true;
        timers.fire();
        expect(await box.flush()).toBe('flushed');
        expect(box.state).toEqual({ size: 0, retrying: false, stopped: false });
        expect(sentIds(send).at(-1)).toEqual([eventId(1), eventId(2)]);
        expect(changes).toContain(true);
    });

    it('waits as long as Retry-After says (429)', async () => {
        const { box, timers } = outbox({
            send: server(() => ({ kind: 'retry', afterMs: 7000 })),
        });

        box.push(1, hit(1));
        await box.flush();

        expect(timers.pending.map((timer) => timer.ms)).toEqual([7000]);
    });

    it('treats a request that throws as a retry', async () => {
        const send = vi.fn(async (): Promise<SendResult> => {
            throw new TypeError('Failed to fetch');
        });
        const { box } = outbox({ send });

        box.push(1, hit(1));

        expect(await box.flush()).toBe('retry');
        expect(box.size).toBe(1);
    });

    it('retries at once when the browser is online again', async () => {
        let online = false;
        const send = server((request) =>
            online
                ? { kind: 'ok', response: answer(request) }
                : { kind: 'retry' },
        );
        const { box, timers } = outbox({ send });

        box.push(1, hit(1));
        await box.flush();
        online = true;

        expect(await box.retryNow()).toBe('flushed');
        expect(timers.pending).toEqual([]);
    });

    it('backs off 1, 2, 4 … seconds up to 30', () => {
        expect([1, 2, 3, 4, 5, 6, 7].map(defaultBackoff)).toEqual([
            1000, 2000, 4000, 8000, 16000, 30000, 30000,
        ]);
    });

    it('stops for good on 409 board_replaced and forgets what it held', async () => {
        const onReplaced = vi.fn();
        const send = server(() => ({
            kind: 'replaced',
            game: { status: 'live', board_epoch: 9 },
        }));
        const { box, storage } = outbox({ send, onReplaced });

        box.push(8, hit(1));

        expect(await box.flush()).toBe('replaced');
        expect(onReplaced).toHaveBeenCalledWith({
            status: 'live',
            board_epoch: 9,
        });
        expect(box.state).toEqual({ size: 0, retrying: false, stopped: true });
        expect(storage.data.has(KEY)).toBe(false);
        expect(box.push(8, hit(2))).toBe(false);
        expect(await box.flush()).toBe('replaced');
        expect(send).toHaveBeenCalledTimes(1);
    });

    it('narrows a refused batch (422) down to the one bad event and drops only it', async () => {
        const bad = eventId(2);
        const send = server((request) =>
            request.events.some((event) => event.event_id === bad)
                ? { kind: 'invalid' }
                : { kind: 'ok', response: answer(request) },
        );
        const storage = memoryStorage();

        storage.setItem(
            KEY,
            JSON.stringify(
                [1, 2, 3, 4].map((n) => ({ epoch: 1, event: hit(n) })),
            ),
        );

        const box = new Outbox({ key: KEY, storage, send });

        expect(await box.flush()).toBe('flushed');
        expect(sentIds(send)).toEqual([
            [eventId(1), eventId(2), eventId(3), eventId(4)],
            [eventId(1)],
            [eventId(2)],
            [eventId(3)],
            [eventId(4)],
        ]);
        expect(box.size).toBe(0);
    });
});

describe('event ids', () => {
    it('are ULIDs that sort by time', () => {
        const zeros = () => new Uint8Array(16);
        const early = ulid(Date.UTC(2026, 10, 9, 9), zeros);
        const late = ulid(Date.UTC(2026, 10, 9, 10), zeros);

        expect(early).toMatch(/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/);
        expect(late > early).toBe(true);
        expect(ulid()).not.toBe(ulid());
    });
});
