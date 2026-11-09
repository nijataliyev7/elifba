import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import { BoardController } from './board';
import type { BridgeScene, HitEffect } from './bridge';
import { BoardBridge } from './bridge';
import type {
    ClientState,
    GameState,
    Round,
    SessionStatus,
    SyncRequest,
} from './contract';
import type { BoardHttp } from './http';
import type { ClientEventName, LiveChannel, SocketState } from './live';
import {
    ackStats,
    LiveBoard,
    LiveRemote,
    LOST_MS,
    PING_MS,
    RETRY_MS,
    STATE_MS,
    TAP_LINGER_MS,
} from './live';
import type { StorageLike } from './outbox';
import { ulid } from './ulid';

/*
 * The live link (docs/architecture.md §4, protocol v1) over a fake channel:
 * the board side with a real board controller, the phone side alone, and
 * both together. Timers are Vitest's fake timers; the fake hub delivers
 * whispers when `flush()` is called, never back to the sender, and only
 * between connected members.
 */

const GAME = '01JC8Z5K2W7Y3Q9H4D6F8G1J0S';

type Sent = { name: ClientEventName; payload: Record<string, unknown> };

type Member = LiveChannel & {
    sent: Sent[];
    connected: boolean;
    closed: boolean;
    connect: () => void;
    disconnect: () => void;
    /** What this member sent under one name. */
    of: (name: ClientEventName) => Record<string, unknown>[];
};

function fakeHub() {
    const members: Member[] = [];
    const queue: (() => void)[] = [];

    function member(): Member {
        const whispers = new Map<string, ((payload: unknown) => void)[]>();
        const statuses: ((payload: unknown) => void)[] = [];
        const subscribed: (() => void)[] = [];
        const sockets: ((state: SocketState) => void)[] = [];

        const self: Member = {
            sent: [],
            connected: false,
            closed: false,
            whisper: (name, payload) => {
                self.sent.push({ name, payload });

                if (!self.connected) {
                    return;
                }

                for (const other of members) {
                    if (other !== self) {
                        queue.push(() => {
                            if (other.connected) {
                                deliver(other, name, payload);
                            }
                        });
                    }
                }
            },
            onWhisper: (name, listener) => {
                whispers.set(name, [...(whispers.get(name) ?? []), listener]);
            },
            onStatus: (listener) => {
                statuses.push(listener);
            },
            onSubscribed: (listener) => {
                subscribed.push(listener);
            },
            onSocket: (listener) => {
                sockets.push(listener);
                listener(self.connected ? 'connected' : 'connecting');
            },
            close: () => {
                self.closed = true;
                self.connected = false;
            },
            connect: () => {
                self.connected = true;
                sockets.forEach((listener) => listener('connected'));
                subscribed.forEach((listener) => listener());
            },
            disconnect: () => {
                self.connected = false;
                sockets.forEach((listener) => listener('disconnected'));
            },
            of: (name) =>
                self.sent
                    .filter((sent) => sent.name === name)
                    .map((sent) => sent.payload),
        };

        listeners.set(self, { whispers, statuses });
        members.push(self);

        return self;
    }

    const listeners = new Map<
        Member,
        {
            whispers: Map<string, ((payload: unknown) => void)[]>;
            statuses: ((payload: unknown) => void)[];
        }
    >();

    function deliver(to: Member, name: string, payload: unknown) {
        for (const listener of listeners.get(to)?.whispers.get(name) ?? []) {
            listener(payload);
        }
    }

    return {
        member,
        /** Deliver everything in flight, including what the deliveries send. */
        flush: () => {
            while (queue.length > 0) {
                queue.shift()?.();
            }
        },
        /** A whisper from outside (a malformed or foreign sender). */
        inject: (to: Member, name: ClientEventName, payload: unknown) => {
            deliver(to, name, payload);
        },
        /** The server's `session.status` to every connected member. */
        broadcastStatus: (payload: unknown) => {
            for (const to of members) {
                if (to.connected) {
                    listeners.get(to)?.statuses.forEach((l) => l(payload));
                }
            }
        },
    };
}

function round(index: number, target: string): Round {
    return {
        id: `round-${index}`,
        index,
        total: 3,
        mechanic: 'find',
        unit: 'letter',
        pack: 'balloons',
        level: 'letters',
        prompt: {
            text: '«Ə» hərfini tap!',
            show: 'Ə',
            image: null,
            audio: null,
            tail: null,
        },
        items: ['a', 'b', 'c', 'd'].map((id) => ({
            id,
            text: id.toUpperCase(),
            image: null,
            content_key: `letter:${id}`,
        })),
        slots: 0,
        solution: { order: 'any', item_ids: [target] },
        effect: 'burst',
    };
}

const ROUNDS = [round(0, 'b'), round(1, 'c'), round(2, 'd')];

/** «nənə» twice over: the tile «nə» fills both slots of each round (§2). */
const SEQUENCE: Round[] = [0, 1].map((index) => ({
    ...round(index, 'a'),
    total: 2,
    mechanic: 'build_word',
    unit: 'word',
    pack: 'animals',
    level: 'words-short',
    prompt: {
        text: 'Sözü hecalardan yığ!',
        show: null,
        image: '/content/openmoji/1F475.svg?v=2',
        audio: null,
        tail: null,
    },
    items: [
        ['a', 'nə'],
        ['b', 'ba'],
        ['c', 'də'],
    ].map(([id, text]) => ({
        id,
        text,
        image: null,
        content_key: `syllable:${text}`,
    })),
    slots: 2,
    solution: { order: 'sequence', item_ids: ['a', 'a'] },
}));

function fakeHttp(rounds: Round[] = ROUNDS) {
    const synced: SyncRequest[] = [];
    const statuses: string[] = [];
    const http: BoardHttp = {
        claim: async () => ({ status: 'live', board_epoch: 5 }),
        state: async (): Promise<GameState> => ({
            game: {
                id: GAME,
                status: 'live',
                board_epoch: 5,
                pack: 'balloons',
                mechanic: 'find',
            },
            rounds,
            progress: [],
        }),
        sync: async (request) => {
            synced.push(request);

            return {
                kind: 'ok',
                response: {
                    results: request.events.map((event) => {
                        const solved =
                            event.type === 'hit' &&
                            rounds.find((r) => r.id === event.round_id)
                                ?.solution.item_ids[0] === event.item_id;

                        return {
                            event_id: event.event_id,
                            status:
                                event.type === 'skip'
                                    ? 'skipped'
                                    : solved
                                      ? 'correct'
                                      : 'miss',
                            round_solved: solved,
                            points: solved ? 3 : null,
                        };
                    }),
                    game: { status: 'live', board_epoch: 5 },
                },
            };
        },
        status: async (status) => {
            statuses.push(status);

            return { kind: 'ok', game: { status, board_epoch: 5 } };
        },
    };

    return {
        http,
        statuses,
        /** Every hit and skip the board sent to `/sync`, in order. */
        events: () => synced.flatMap((request) => request.events),
    };
}

function memoryStorage(): StorageLike {
    const data = new Map<string, string>();

    return {
        getItem: (key) => data.get(key) ?? null,
        setItem: (key, value) => {
            data.set(key, value);
        },
        removeItem: (key) => {
            data.delete(key);
        },
    };
}

/** A board on the wall, loaded and started, with its live link on the hub. */
async function wallBoard(
    hub: ReturnType<typeof fakeHub>,
    start = true,
    rounds: Round[] = ROUNDS,
) {
    const server = fakeHttp(rounds);
    const bridge = new BoardBridge();
    const log: string[] = [];
    const scene: BridgeScene = {
        showRound: (shown) => log.push(`show:${shown.id}`),
        applyHit: (effect: HitEffect) =>
            log.push(`hit:${effect.itemId}:${effect.status}`),
        setPaused: (paused) => log.push(paused ? 'pause' : 'play'),
    };

    bridge.connect(scene);

    let tabListener: ((message: unknown) => void) | null = null;
    const controller = new BoardController({
        gameId: GAME,
        status: 'live',
        epoch: 4,
        studentIds: ['s1', 's2', 's3'],
        http: server.http,
        bridge,
        storage: memoryStorage(),
        channel: {
            post: () => {},
            listen: (listener) => {
                tabListener = listener;
            },
            close: () => {},
        },
    });

    await controller.load();

    if (start) {
        controller.start();
    }

    log.length = 0;

    const channel = hub.member();
    const live = new LiveBoard({ channel, board: controller });

    channel.connect();

    /** Another tab of this browser claims the same game (board.ts's tab channel). */
    const claimElsewhere = () =>
        tabListener?.({ type: 'claimed', game: GAME, epoch: 6 });

    return {
        controller,
        live,
        channel,
        log,
        server,
        bridge,
        claimElsewhere,
    };
}

/** A member that plays the phone by hand. */
function handPhone(hub: ReturnType<typeof fakeHub>) {
    const phone = hub.member();

    phone.connect();

    return {
        phone,
        hello: () => phone.whisper('hello', { v: 1, device_id: 'phone-1' }),
        hit: (roundId: string, itemId: string, eventId = ulid(Date.now())) => {
            phone.whisper('hit', {
                v: 1,
                event_id: eventId,
                round_id: roundId,
                item_id: itemId,
                client_at: new Date().toISOString(),
            });

            return eventId;
        },
    };
}

/** A member that plays the board by hand. */
function handBoard(hub: ReturnType<typeof fakeHub>) {
    const board = hub.member();

    board.connect();

    return {
        board,
        state: (change: Partial<ClientState> = {}) =>
            board.whisper('state', {
                v: 1,
                board_epoch: 5,
                status: 'live',
                round_id: 'round-0',
                round_index: 0,
                student_id: null,
                hit_item_ids: [],
                misses: 0,
                last_points: null,
                ...change,
            }),
        ack: (eventId: string, status = 'correct') =>
            board.whisper('ack', { v: 1, event_id: eventId, status }),
    };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(Date.UTC(2026, 10, 9, 9, 0, 0));
});

afterEach(() => {
    vi.useRealTimers();
});

describe('the board side', () => {
    it('answers hello with its state and hides the pairing card', async () => {
        const hub = fakeHub();
        const { live, channel } = await wallBoard(hub);
        const { hello } = handPhone(hub);

        // Subscribed: one state for a remote that may be waiting.
        expect(channel.of('state')).toHaveLength(1);
        expect(live.getView()).toMatchObject({
            pairing: true,
            remote: 'none',
        });

        hello();
        hub.flush();

        expect(live.getView()).toMatchObject({
            pairing: false,
            remote: 'connected',
            socket: 'connected',
        });
        expect(channel.of('state').at(-1)).toEqual({
            v: 1,
            board_epoch: 5,
            status: 'live',
            round_id: 'round-0',
            round_index: 0,
            student_id: null,
            hit_item_ids: [],
            misses: 0,
            last_points: null,
        });
    });

    it('bursts a remote hit, acks it before the state, and queues it with the phone’s event id', async () => {
        const hub = fakeHub();
        const { channel, log, server } = await wallBoard(hub);
        const { hit } = handPhone(hub);

        const eventId = hit('round-0', 'b');
        hub.flush();

        expect(log).toEqual(['hit:b:correct']);

        const names = channel.sent.map((sent) => sent.name);
        expect(names.slice(-2)).toEqual(['ack', 'state']);
        expect(channel.of('ack')).toEqual([
            { v: 1, event_id: eventId, status: 'correct' },
        ]);
        expect(channel.of('state').at(-1)).toMatchObject({
            round_id: 'round-0',
            hit_item_ids: ['b'],
        });

        await vi.advanceTimersByTimeAsync(0);
        expect(server.events()).toEqual([
            expect.objectContaining({
                type: 'hit',
                event_id: eventId,
                round_id: 'round-0',
                item_id: 'b',
                source: 'remote',
            }),
        ]);
    });

    it('applies an event id once and repeats its ack for a retry', async () => {
        const hub = fakeHub();
        const { channel, log, server } = await wallBoard(hub);
        const { hit } = handPhone(hub);

        const eventId = hit('round-0', 'a');
        hub.flush();
        hit('round-0', 'a', eventId);
        hub.flush();

        expect(log).toEqual(['hit:a:miss']);
        expect(channel.of('ack')).toEqual([
            { v: 1, event_id: eventId, status: 'miss' },
            { v: 1, event_id: eventId, status: 'miss' },
        ]);
        await vi.advanceTimersByTimeAsync(0);
        expect(server.events()).toHaveLength(1);
    });

    it('answers stale to a hit that is not on the current round', async () => {
        const hub = fakeHub();
        const { channel, server } = await wallBoard(hub);
        const { hit } = handPhone(hub);

        const later = hit('round-1', 'c');
        const unknown = hit('round-9', 'a');
        hub.flush();

        expect(channel.of('ack')).toEqual([
            { v: 1, event_id: later, status: 'stale' },
            { v: 1, event_id: unknown, status: 'stale' },
        ]);
        await vi.advanceTimersByTimeAsync(0);
        expect(server.events()).toEqual([]);
    });

    it('answers stale while the board waits for «Başla»', async () => {
        const hub = fakeHub();
        const { channel, log } = await wallBoard(hub, false);
        const { hit } = handPhone(hub);

        const eventId = hit('round-0', 'b');
        hub.flush();

        expect(log).toEqual([]);
        expect(channel.of('ack')).toEqual([
            { v: 1, event_id: eventId, status: 'stale' },
        ]);
        // Waiting for «Başla»: no round on the canvas yet.
        expect(channel.of('state').at(-1)).toMatchObject({
            status: 'live',
            round_id: null,
            round_index: null,
        });
    });

    it('drops malformed events without an answer', async () => {
        const hub = fakeHub();
        const { live, channel, log } = await wallBoard(hub);
        const sentBefore = channel.sent.length;
        const good = {
            v: 1,
            event_id: ulid(Date.now()),
            round_id: 'round-0',
            item_id: 'b',
            client_at: new Date().toISOString(),
        };

        for (const bad of [
            null,
            'client-hit',
            { ...good, v: 2 },
            { ...good, event_id: 'not-a-ulid' },
            { ...good, item_id: '' },
            { ...good, student_name: 'Aysel' },
            Object.fromEntries(
                Object.entries(good).filter(([key]) => key !== 'v'),
            ),
        ]) {
            hub.inject(channel, 'hit', bad);
        }

        hub.inject(channel, 'student', { v: 1, student_id: 5 });
        hub.inject(channel, 'control', { v: 1, action: 'restart' });
        hub.inject(channel, 'hello', { v: 1 });
        hub.inject(channel, 'ping', { v: 1, t: 'now' });

        expect(log).toEqual([]);
        expect(channel.sent.length).toBe(sentBefore);
        expect(live.getView().remote).toBe('none');
    });

    it('treats the other source’s touch on the same item within 800 ms as the same touch', async () => {
        const hub = fakeHub();
        const { channel, bridge, log, server } = await wallBoard(hub);
        const { hit } = handPhone(hub);

        // A child misses «a» on the wall; the teacher taps «a» at once.
        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        vi.advanceTimersByTime(300);
        const echo = hit('round-0', 'a');
        hub.flush();

        expect(channel.of('ack').at(-1)).toEqual({
            v: 1,
            event_id: echo,
            status: 'duplicate',
        });

        // The teacher taps «a» again later: a touch of its own.
        vi.advanceTimersByTime(800);
        const later = hit('round-0', 'a');
        hub.flush();

        expect(channel.of('ack').at(-1)).toEqual({
            v: 1,
            event_id: later,
            status: 'miss',
        });

        // And the other way round: the phone first, the wall 200 ms later.
        const first = hit('round-0', 'd');
        hub.flush();
        vi.advanceTimersByTime(200);
        bridge.itemTouched({ roundId: 'round-0', itemId: 'd' });

        expect(channel.of('ack').at(-1)).toEqual({
            v: 1,
            event_id: first,
            status: 'miss',
        });
        expect(log).toEqual(['hit:a:miss', 'hit:a:miss', 'hit:d:miss']);

        await vi.advanceTimersByTimeAsync(0);
        expect(
            server
                .events()
                .map((event) =>
                    event.type === 'hit'
                        ? `${event.source}:${event.item_id}`
                        : '',
                ),
        ).toEqual(['board:a', 'remote:a', 'remote:d']);
    });

    it('takes a double tap on a reused tile as two hits, and the wall and the phone on it within 800 ms as one', async () => {
        const hub = fakeHub();
        const { channel, bridge, log, server } = await wallBoard(
            hub,
            true,
            SEQUENCE,
        );
        const { hit } = handPhone(hub);
        const lastAck = () => channel.of('ack').at(-1);

        // «nə-nə» from the phone: two taps on «nə» at once, two hits.
        const first = hit('round-0', 'a');
        const second = hit('round-0', 'a');
        hub.flush();

        expect(channel.of('ack').slice(-2)).toEqual([
            { v: 1, event_id: first, status: 'correct' },
            { v: 1, event_id: second, status: 'correct' },
        ]);
        expect(channel.of('state').at(-1)).toMatchObject({
            round_id: 'round-0',
            hit_item_ids: ['a', 'a'],
            misses: 0,
        });

        // The next round, after the solved effect.
        vi.advanceTimersByTime(1600);
        expect(log.at(-1)).toBe('show:round-1');

        // A child taps «nə» on the wall, the teacher taps it 300 ms later:
        // the same touch.
        bridge.itemTouched({ roundId: 'round-1', itemId: 'a' });
        vi.advanceTimersByTime(300);
        const echo = hit('round-1', 'a');
        hub.flush();

        expect(lastAck()).toEqual({
            v: 1,
            event_id: echo,
            status: 'duplicate',
        });
        expect(channel.of('state').at(-1)).toMatchObject({
            round_id: 'round-1',
            hit_item_ids: ['a'],
        });

        // 800 ms on, the teacher's tap is the second «nə».
        vi.advanceTimersByTime(800);
        const later = hit('round-1', 'a');
        hub.flush();

        expect(lastAck()).toEqual({
            v: 1,
            event_id: later,
            status: 'correct',
        });
        expect(log.filter((entry) => entry.startsWith('hit:'))).toEqual([
            'hit:a:correct',
            'hit:a:correct',
            'hit:a:correct',
            'hit:a:correct',
        ]);

        await vi.advanceTimersByTimeAsync(0);
        expect(
            server
                .events()
                .map((event) =>
                    event.type === 'hit'
                        ? `${event.round_id}:${event.source}:${event.item_id}`
                        : '',
                ),
        ).toEqual([
            'round-0:remote:a',
            'round-0:remote:a',
            'round-1:board:a',
            'round-1:remote:a',
        ]);
    });

    it('lets the phone choose who is at the board', async () => {
        const hub = fakeHub();
        const { controller, channel } = await wallBoard(hub);
        const { phone } = handPhone(hub);

        phone.whisper('student', { v: 1, student_id: 's2' });
        hub.flush();

        expect(controller.getView().studentId).toBe('s2');
        expect(channel.of('state').at(-1)).toMatchObject({ student_id: 's2' });

        // Not a student of this class: the whole class.
        phone.whisper('student', { v: 1, student_id: 'someone-else' });
        hub.flush();

        expect(controller.getView().studentId).toBeNull();
    });

    it('pauses, resumes, skips, ends and shows the pairing card for the phone', async () => {
        const hub = fakeHub();
        const { controller, live, channel, server, log } = await wallBoard(hub);
        const { phone } = handPhone(hub);
        const control = async (action: string) => {
            phone.whisper('control', { v: 1, action });
            hub.flush();
            await vi.advanceTimersByTimeAsync(0);
        };

        await control('pause');
        expect(controller.getView().status).toBe('paused');
        expect(channel.of('state').at(-1)).toMatchObject({ status: 'paused' });

        await control('resume');
        expect(controller.getView().status).toBe('live');

        await control('skip');
        expect(log).toContain('show:round-1');
        expect(channel.of('state').at(-1)).toMatchObject({
            round_id: 'round-1',
            round_index: 1,
        });

        live.hidePairing();
        await control('pairing');
        expect(live.getView().pairing).toBe(true);

        await control('end');
        expect(server.statuses).toEqual(['paused', 'live', 'finished']);
        expect(controller.getView().phase).toBe('finished');
        expect(channel.of('state').at(-1)).toMatchObject({
            status: 'finished',
            round_id: null,
        });
    });

    it('sends its state every 5 s once a remote is there, and says the remote is gone after 10 s without a ping', async () => {
        const hub = fakeHub();
        const { live, channel } = await wallBoard(hub);
        const { phone, hello } = handPhone(hub);

        // No remote yet: nothing every 5 s.
        vi.advanceTimersByTime(STATE_MS * 3);
        expect(channel.of('state')).toHaveLength(1);

        hello();
        hub.flush();
        const afterHello = channel.of('state').length;

        vi.advanceTimersByTime(STATE_MS);
        expect(channel.of('state')).toHaveLength(afterHello + 1);

        // Pings keep it connected.
        for (let i = 0; i < 4; i++) {
            vi.advanceTimersByTime(PING_MS);
            phone.whisper('ping', { v: 1, t: Date.now() });
            hub.flush();
        }

        expect(live.getView().remote).toBe('connected');

        vi.advanceTimersByTime(LOST_MS - 1);
        expect(live.getView().remote).toBe('connected');
        vi.advanceTimersByTime(1);
        expect(live.getView().remote).toBe('lost');

        // Still told every 5 s, in case it comes back; a ping brings it back.
        const lostAt = channel.of('state').length;
        vi.advanceTimersByTime(STATE_MS);
        expect(channel.of('state')).toHaveLength(lostAt + 1);

        phone.whisper('ping', { v: 1, t: Date.now() });
        hub.flush();
        expect(live.getView().remote).toBe('connected');
    });

    it('applies the server’s session.status', async () => {
        const hub = fakeHub();
        const { controller } = await wallBoard(hub);

        hub.broadcastStatus({ v: 1, status: 'paused' });
        expect(controller.getView().status).toBe('paused');

        hub.broadcastStatus({ v: 1, status: 'nonsense' });
        expect(controller.getView().status).toBe('paused');

        hub.broadcastStatus({ v: 1, status: 'finished' });
        expect(controller.getView().phase).toBe('finished');
    });

    it('stays silent once another window has replaced it', async () => {
        const hub = fakeHub();
        const { controller, channel, claimElsewhere } = await wallBoard(hub);
        const { hello, hit } = handPhone(hub);

        claimElsewhere();
        expect(controller.getView().phase).toBe('replaced');

        const before = channel.sent.length;
        hello();
        hit('round-0', 'b');
        hub.flush();
        vi.advanceTimersByTime(STATE_MS * 2);

        expect(channel.sent.length).toBe(before);
    });
});

describe('the phone side', () => {
    function phone(hub: ReturnType<typeof fakeHub>) {
        const channel = hub.member();
        const remote = new LiveRemote({ channel, deviceId: 'phone-1' });

        return { channel, remote };
    }

    it('says hello on every subscription, the first and after a reconnect', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);

        expect(remote.getView().socket).toBe('connecting');

        channel.connect();
        expect(channel.of('hello')).toEqual([{ v: 1, device_id: 'phone-1' }]);
        expect(remote.getView().socket).toBe('connected');

        channel.disconnect();
        expect(remote.getView().socket).toBe('disconnected');

        channel.connect();
        expect(channel.of('hello')).toHaveLength(2);
    });

    it('keeps a tap pending until its ack and measures ack_ms', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);
        const board = handBoard(hub);

        channel.connect();
        const eventId = remote.tap('round-0', 'b');

        expect(eventId).not.toBeNull();
        expect(channel.of('hit')).toEqual([
            {
                v: 1,
                event_id: eventId,
                round_id: 'round-0',
                item_id: 'b',
                client_at: '2026-11-09T09:00:00.000Z',
            },
        ]);
        expect(remote.getView().taps).toEqual([
            expect.objectContaining({ itemId: 'b', status: 'pending' }),
        ]);

        vi.advanceTimersByTime(120);
        board.ack(eventId ?? '', 'correct');
        hub.flush();

        expect(remote.getView().taps).toEqual([
            expect.objectContaining({ itemId: 'b', status: 'correct' }),
        ]);
        expect(remote.getView().ackMs).toEqual([120]);

        vi.advanceTimersByTime(TAP_LINGER_MS);
        expect(remote.getView().taps).toEqual([]);
    });

    it('sends a tap once more after 1 s without an ack, with the same event id, and only once', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);

        channel.connect();
        const eventId = remote.tap('round-0', 'b');

        vi.advanceTimersByTime(RETRY_MS - 1);
        expect(channel.of('hit')).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(channel.of('hit')).toHaveLength(2);
        expect(channel.of('hit')[1]).toEqual(channel.of('hit')[0]);
        expect(channel.of('hit')[1].event_id).toBe(eventId);
        expect(remote.getView().taps[0]).toMatchObject({
            status: 'pending',
            retried: true,
        });

        vi.advanceTimersByTime(RETRY_MS);
        expect(channel.of('hit')).toHaveLength(2);
        expect(remote.getView().taps[0].status).toBe('lost');

        vi.advanceTimersByTime(TAP_LINGER_MS);
        expect(remote.getView().taps).toEqual([]);
    });

    it('takes the first ack of a retried tap and ignores the second', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);
        const board = handBoard(hub);

        channel.connect();
        const eventId = remote.tap('round-0', 'a') ?? '';

        vi.advanceTimersByTime(RETRY_MS + 200);
        board.ack(eventId, 'miss');
        board.ack(eventId, 'miss');
        hub.flush();

        expect(remote.getView().ackMs).toEqual([RETRY_MS + 200]);
        expect(remote.getView().taps[0].status).toBe('miss');

        // An ack for a tap this phone never made (another phone's).
        board.ack(ulid(Date.now()), 'correct');
        hub.flush();
        expect(remote.getView().ackMs).toHaveLength(1);
    });

    it('refuses taps while the socket is down', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);

        expect(remote.tap('round-0', 'b')).toBeNull();

        channel.connect();
        channel.disconnect();
        expect(remote.tap('round-0', 'b')).toBeNull();
        expect(channel.of('hit')).toEqual([]);
    });

    it('ignores a state from an older board', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);
        const board = handBoard(hub);

        channel.connect();
        board.state({ board_epoch: 7, round_id: 'round-2', round_index: 2 });
        hub.flush();
        expect(remote.getView().state?.round_id).toBe('round-2');

        board.state({ board_epoch: 6, round_id: 'round-0', round_index: 0 });
        hub.flush();
        expect(remote.getView().state).toMatchObject({
            board_epoch: 7,
            round_id: 'round-2',
        });

        // A reloaded board has a higher epoch: taken, and told hello.
        expect(channel.of('hello')).toHaveLength(1);
        board.state({ board_epoch: 8, round_id: 'round-2', misses: 1 });
        hub.flush();
        expect(remote.getView().state).toMatchObject({
            board_epoch: 8,
            misses: 1,
        });
        expect(channel.of('hello')).toHaveLength(2);

        // Malformed: dropped.
        board.state({ board_epoch: -1 });
        board.board.whisper('state', { v: 1 });
        hub.flush();
        expect(remote.getView().state?.board_epoch).toBe(8);
    });

    it('pings every 5 s while connected', () => {
        const hub = fakeHub();
        const { channel } = phone(hub);

        channel.connect();
        vi.advanceTimersByTime(PING_MS * 3);
        expect(channel.of('ping')).toHaveLength(3);
        expect(channel.of('ping')[0]).toEqual({
            v: 1,
            t: Date.UTC(2026, 10, 9, 9, 0, 5),
        });

        channel.disconnect();
        vi.advanceTimersByTime(PING_MS * 3);
        expect(channel.of('ping')).toHaveLength(3);

        channel.connect();
        vi.advanceTimersByTime(PING_MS);
        expect(channel.of('ping')).toHaveLength(4);
    });

    it('says the board is not connected after 10 s without a state', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);
        const board = handBoard(hub);

        channel.connect();
        expect(remote.getView().board).toBe('waiting');

        vi.advanceTimersByTime(LOST_MS);
        expect(remote.getView().board).toBe('lost');

        board.state();
        hub.flush();
        expect(remote.getView().board).toBe('connected');

        vi.advanceTimersByTime(STATE_MS);
        board.state();
        hub.flush();
        vi.advanceTimersByTime(LOST_MS - 1);
        expect(remote.getView().board).toBe('connected');
        vi.advanceTimersByTime(1);
        expect(remote.getView().board).toBe('lost');
    });

    it('shows a student at once and sends controls', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);
        const board = handBoard(hub);

        channel.connect();
        board.state();
        hub.flush();

        remote.setStudent('s3');
        remote.control('skip');

        expect(remote.getView().state?.student_id).toBe('s3');
        expect(channel.of('student')).toEqual([{ v: 1, student_id: 's3' }]);
        expect(channel.of('control')).toEqual([{ v: 1, action: 'skip' }]);
    });

    it('follows the server’s session.status', () => {
        const hub = fakeHub();
        const { channel, remote } = phone(hub);
        const board = handBoard(hub);

        channel.connect();
        board.state({ status: 'live' });
        hub.flush();

        const statuses: SessionStatus[] = [];
        remote.subscribe(() => {
            const status = remote.getView().status;

            if (status !== null) {
                statuses.push(status);
            }
        });

        hub.broadcastStatus({ v: 1, status: 'finished' });
        expect(remote.getView().status).toBe('finished');
        expect(statuses).toEqual(['finished']);
    });
});

describe('phone and board together', () => {
    it('pairs, plays and recovers from a dropped socket on both sides', async () => {
        const hub = fakeHub();
        const { controller, channel: wall, log, server } = await wallBoard(hub);
        const phoneChannel = hub.member();
        const remote = new LiveRemote({
            channel: phoneChannel,
            deviceId: 'phone-1',
        });

        phoneChannel.connect();
        hub.flush();

        expect(remote.getView().state).toMatchObject({
            board_epoch: 5,
            round_id: 'round-0',
        });

        // A tap: burst on the wall, ack on the phone.
        remote.tap('round-0', 'b');
        hub.flush();
        expect(log).toEqual(['hit:b:correct']);
        expect(remote.getView().taps[0].status).toBe('correct');

        // The phone's socket drops; a tap now is refused, the board plays on.
        phoneChannel.disconnect();
        expect(remote.tap('round-0', 'a')).toBeNull();
        vi.advanceTimersByTime(1600);
        expect(controller.getView().round?.id).toBe('round-1');

        // Back: hello, and the phone rebuilds from the board's state.
        phoneChannel.connect();
        hub.flush();
        expect(remote.getView().state).toMatchObject({
            round_id: 'round-1',
            round_index: 1,
        });

        // The board's socket drops: a tap gets no ack; the retry after
        // 1 s finds the board back and is burst once.
        wall.disconnect();
        const eventId = remote.tap('round-1', 'c') ?? '';
        hub.flush();
        expect(log).toEqual(['hit:b:correct', 'show:round-1']);

        wall.connect();
        hub.flush();
        vi.advanceTimersByTime(RETRY_MS);
        hub.flush();

        expect(log).toEqual(['hit:b:correct', 'show:round-1', 'hit:c:correct']);
        expect(remote.getView().taps.at(-1)).toMatchObject({
            eventId,
            status: 'correct',
            retried: true,
        });

        await vi.advanceTimersByTimeAsync(0);
        expect(server.events().map((event) => event.event_id)).toContain(
            eventId,
        );
    });
});

describe('ackStats', () => {
    it('counts and takes the median and the 95th percentile', () => {
        expect(ackStats([])).toEqual({ count: 0, p50: null, p95: null });
        expect(ackStats([40])).toEqual({ count: 1, p50: 40, p95: 40 });

        const samples = Array.from({ length: 100 }, (_, i) => 100 - i);

        expect(ackStats(samples)).toEqual({ count: 100, p50: 50, p95: 95 });
    });
});
