import { describe, expect, it, vi } from 'vite-plus/test';
import type { BoardControllerOptions, TabChannel } from './board';
import { BoardController, boardStorageKey } from './board';
import type { BridgeScene, HitEffect } from './bridge';
import { BoardBridge } from './bridge';
import type {
    GameState,
    GameStatus,
    Round,
    RoundProgress,
    SyncRequest,
} from './contract';
import type { BoardHttp } from './http';
import type { SendResult, StorageLike, Timers } from './outbox';
import { ulid } from './ulid';

/*
 * The board controller (docs/architecture.md §4): load order, a touch from
 * judge to effect to outbox, moving on after a solved round, who is at the
 * board, fencing and the end of a game.
 */

const GAME = '01JC8Z5K2W7Y3Q9H4D6F8G1J0S';

function round(index: number, target = 'b'): Round {
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

const ROUNDS = [round(0), round(1, 'c'), round(2, 'd')];

/** A `build_word` round, «nənə»: the tile «nə» fills both slots (§2). */
function nene(index: number): Round {
    return {
        id: `round-${index}`,
        index,
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
        effect: 'burst',
    };
}

const SEQUENCE = [nene(0), nene(1)];

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

function manualTimers(): Timers & {
    pending: { handle: number; ms: number; callback: () => void }[];
    fire: (ms?: number) => void;
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
        fire: (ms) => {
            const at =
                ms === undefined
                    ? 0
                    : pending.findIndex((timer) => timer.ms === ms);

            if (at >= 0) {
                const [timer] = pending.splice(at, 1);

                timer.callback();
            }
        },
    };
}

/** A server that judges nothing: a hit on the first solution item is answered correct and solved, with 3 points. */
function fakeServer(progress: RoundProgress[] = [], rounds: Round[] = ROUNDS) {
    const calls: string[] = [];
    let epoch = 4;
    let online = true;
    let replaced = false;
    const synced: SyncRequest[] = [];

    const http: BoardHttp = {
        claim: vi.fn(async (): Promise<GameStatus> => {
            calls.push('claim');
            epoch += 1;

            return { status: 'live', board_epoch: epoch };
        }),
        state: vi.fn(async (): Promise<GameState> => {
            calls.push('state');

            return {
                game: {
                    id: GAME,
                    status: 'live',
                    board_epoch: epoch,
                    pack: 'balloons',
                    mechanic: 'find',
                },
                rounds,
                progress,
            };
        }),
        sync: vi.fn(async (request: SyncRequest): Promise<SendResult> => {
            calls.push(`sync@${request.board_epoch}`);

            if (!online) {
                return { kind: 'retry' };
            }

            if (replaced) {
                return {
                    kind: 'replaced',
                    game: { status: 'live', board_epoch: epoch + 1 },
                };
            }

            synced.push(request);

            return {
                kind: 'ok',
                response: {
                    results: request.events.map((event) => {
                        const target =
                            event.type === 'hit' &&
                            rounds.find((r) => r.id === event.round_id)
                                ?.solution.item_ids[0] === event.item_id;

                        return {
                            event_id: event.event_id,
                            status:
                                event.type === 'skip'
                                    ? 'skipped'
                                    : target
                                      ? 'correct'
                                      : 'miss',
                            round_solved: target,
                            points: target ? 3 : null,
                        };
                    }),
                    game: { status: 'live', board_epoch: request.board_epoch },
                },
            };
        }),
        status: vi.fn(async (status) => {
            calls.push(`status:${status}`);

            return online
                ? {
                      kind: 'ok' as const,
                      game: { status, board_epoch: epoch },
                  }
                : { kind: 'failed' as const };
        }),
    };

    return {
        http,
        calls,
        synced,
        goOffline: () => {
            online = false;
        },
        goOnline: () => {
            online = true;
        },
        replaceBoard: () => {
            replaced = true;
        },
    };
}

function recordingScene() {
    const log: string[] = [];
    const scene: BridgeScene = {
        showRound: (shown) => log.push(`show:${shown.id}`),
        applyHit: (effect: HitEffect) =>
            log.push(`hit:${effect.itemId}:${effect.status}`),
        setPaused: (paused) => log.push(paused ? 'pause' : 'play'),
    };

    return { scene, log };
}

let ids = 0;

function setup(
    overrides: Partial<BoardControllerOptions> & {
        storage?: StorageLike & { data: Map<string, string> };
        server?: ReturnType<typeof fakeServer>;
    } = {},
) {
    const storage = overrides.storage ?? memoryStorage();
    const server = overrides.server ?? fakeServer();
    const timers = manualTimers();
    const bridge = new BoardBridge();
    const { scene, log } = recordingScene();

    bridge.connect(scene);
    // Connecting replays the pause state; the tests watch what follows.
    log.length = 0;

    const controller = new BoardController({
        gameId: GAME,
        status: 'live',
        epoch: 4,
        studentIds: ['s1', 's2', 's3'],
        http: server.http,
        bridge,
        storage,
        timers,
        now: () => Date.UTC(2026, 10, 9, 9, 0, 0),
        makeId: () => `01JC8ZA${String(++ids).padStart(19, '0')}`,
        ...overrides,
    });

    return { controller, storage, server, timers, bridge, log };
}

const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

describe('loading', () => {
    it('claims, loads the rounds and waits for «Başla»', async () => {
        const { controller, server, log } = setup();

        await controller.load();

        expect(server.calls).toEqual(['claim', 'state']);
        expect(controller.getView()).toMatchObject({
            phase: 'ready',
            epoch: 5,
            resumed: false,
        });
        expect(log).toEqual([]);

        controller.start();

        expect(controller.getView().phase).toBe('playing');
        expect(log).toEqual(['show:round-0', 'play']);
    });

    it('sends what the tab still holds before it claims again, then resumes at the same round', async () => {
        const storage = memoryStorage();

        storage.setItem(
            boardStorageKey(GAME, 'outbox'),
            JSON.stringify([
                {
                    epoch: 4,
                    event: {
                        type: 'hit',
                        event_id: '01JC8ZA0000000000000000777',
                        round_id: 'round-0',
                        item_id: 'b',
                        source: 'board',
                        student_id: null,
                        client_at: null,
                    },
                },
            ]),
        );

        // The server already has round 0 solved, as after that sync.
        const server = fakeServer([
            {
                round_id: 'round-0',
                hit_item_ids: ['b'],
                misses: 0,
                solved: true,
                skipped: false,
            },
            {
                round_id: 'round-1',
                hit_item_ids: [],
                misses: 1,
                solved: false,
                skipped: false,
            },
        ]);
        const { controller, log } = setup({ storage, server });

        await controller.load();

        // The drained hit solved round 0: its points come back with it.
        expect(server.calls).toEqual(['sync@4', 'claim', 'state']);
        expect(storage.data.has(boardStorageKey(GAME, 'outbox'))).toBe(false);
        expect(controller.getView()).toMatchObject({
            phase: 'ready',
            resumed: true,
            points: 3,
        });

        controller.start();

        expect(log[0]).toBe('show:round-1');
        expect(controller.getView().progress?.misses).toBe(1);
    });

    it('opens a game whose rounds are all over on its result', async () => {
        const server = fakeServer(
            ROUNDS.map((r) => ({
                round_id: r.id,
                hit_item_ids: [],
                misses: 0,
                solved: false,
                skipped: true,
            })),
        );
        const { controller } = setup({ server });

        await controller.load();

        expect(controller.getView().phase).toBe('finished');
    });
});

describe('stars', () => {
    it('start from the points the page carried and add each solved round once', async () => {
        const { controller, bridge, server } = setup({
            points: { 'round-x': 2 },
        });

        await controller.load();
        controller.start();
        expect(controller.getView().points).toBe(2);

        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        await settle();

        expect(server.synced).toHaveLength(1);
        expect(controller.getView().points).toBe(5);
    });
});

describe('a touch', () => {
    it('plays its effect before anything is sent, and queues it with the student at the board', async () => {
        const { controller, server, bridge, log } = setup();

        await controller.load();
        controller.start();
        controller.setStudent('s2');

        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });

        // The miss is on the canvas in the same call; the request comes after.
        expect(log.at(-1)).toBe('hit:a:miss');
        expect(controller.getView().progress?.misses).toBe(1);

        await settle();

        expect(server.synced[0]).toEqual({
            board_epoch: 5,
            events: [
                {
                    type: 'hit',
                    event_id: expect.stringMatching(/^01JC8ZA/),
                    round_id: 'round-0',
                    item_id: 'a',
                    source: 'board',
                    student_id: 's2',
                    client_at: '2026-11-09T09:00:00.000Z',
                },
            ],
        });
    });

    it('moves on after the solved effect, fills in the points and ends after the last round', async () => {
        const { controller, timers, bridge, log } = setup({
            solvedDelayMs: 1500,
        });

        await controller.load();
        controller.start();

        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        await settle();

        expect(log.at(-1)).toBe('hit:b:correct');
        expect(controller.getView()).toMatchObject({
            round: { id: 'round-0' },
            points: 3,
        });

        // A touch on the old balloons during the effect is stale and not sent.
        bridge.itemTouched({ roundId: 'round-0', itemId: 'c' });
        expect(log.at(-1)).toBe('hit:c:stale');

        timers.fire(1500);
        expect(log.at(-1)).toBe('show:round-1');

        controller.skip();
        expect(log.at(-1)).toBe('show:round-2');

        bridge.itemTouched({ roundId: 'round-2', itemId: 'd' });
        timers.fire(1500);
        await settle();

        expect(controller.getView()).toMatchObject({
            phase: 'finished',
            points: 6,
            tally: { rounds: 3, solved: 2, skipped: 1, misses: 0 },
            pending: 0,
        });
    });

    it('is ignored while the game is paused', async () => {
        const { controller, bridge, log, server } = setup();

        await controller.load();
        controller.start();
        await controller.pause();

        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });

        expect(log).not.toContain('hit:b:correct');
        expect(server.calls).toContain('status:paused');
        expect(controller.getView().status).toBe('paused');

        await controller.resume();
        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });

        expect(log.at(-1)).toBe('hit:b:correct');
    });
});

describe('a sequence round with a reused tile', () => {
    const hits = (server: ReturnType<typeof fakeServer>) =>
        server.synced
            .flatMap((request) => request.events)
            .map((event) =>
                event.type === 'hit'
                    ? `${event.source}:${event.item_id}`
                    : event.type,
            );

    it('takes two touches of the same tile from one source as two hits', async () => {
        const server = fakeServer([], SEQUENCE);
        const { controller, bridge, timers, log } = setup({
            server,
            solvedDelayMs: 1600,
        });

        await controller.load();
        controller.start();

        // A child taps «nə» twice in the same moment: «nə-nə».
        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        expect(controller.getView().progress).toMatchObject({
            hit_item_ids: ['a'],
            solved: false,
        });

        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });

        expect(log.slice(-2)).toEqual(['hit:a:correct', 'hit:a:correct']);
        expect(controller.getView().progress).toMatchObject({
            hit_item_ids: ['a', 'a'],
            misses: 0,
            solved: true,
        });

        await settle();
        expect(hits(server)).toEqual(['board:a', 'board:a']);

        timers.fire(1600);
        expect(log.at(-1)).toBe('show:round-1');
    });

    it('takes the wall and the phone on the same tile within 800 ms as one touch, and after that as two', async () => {
        let now = Date.UTC(2026, 10, 9, 9, 0, 0);
        const server = fakeServer([], SEQUENCE);
        const { controller, bridge, log } = setup({ server, now: () => now });

        await controller.load();
        controller.start();

        // A child taps «nə» on the wall; the teacher taps it 300 ms later.
        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        now += 300;
        expect(
            controller.touch(
                { roundId: 'round-0', itemId: 'a' },
                { source: 'remote', eventId: ulid(now) },
            ),
        ).toMatchObject({ status: 'duplicate', solved: false });
        expect(controller.getView().progress?.hit_item_ids).toEqual(['a']);

        // Another 500 ms on, the phone's tap is the second «nə».
        now += 500;
        expect(
            controller.touch(
                { roundId: 'round-0', itemId: 'a' },
                { source: 'remote', eventId: ulid(now) },
            ),
        ).toMatchObject({ status: 'correct', solved: true });
        expect(controller.getView().progress).toMatchObject({
            hit_item_ids: ['a', 'a'],
            solved: true,
        });
        expect(log).toEqual([
            'show:round-0',
            'play',
            'hit:a:correct',
            'hit:a:correct',
        ]);

        await settle();
        expect(hits(server)).toEqual(['board:a', 'remote:a']);
    });

    it('resumes a reloaded round with its filled slots', async () => {
        const server = fakeServer(
            [
                {
                    round_id: 'round-0',
                    hit_item_ids: ['a'],
                    misses: 1,
                    solved: false,
                    skipped: false,
                },
            ],
            SEQUENCE,
        );
        const shown: RoundProgress[] = [];
        const { controller, bridge } = setup({ server });

        bridge.connect({
            showRound: (_, progress) => shown.push(progress),
            applyHit: () => {},
            setPaused: () => {},
        });
        await controller.load();
        controller.start();

        expect(shown.at(-1)).toMatchObject({
            round_id: 'round-0',
            hit_item_ids: ['a'],
        });

        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        expect(controller.getView().progress).toMatchObject({
            hit_item_ids: ['a', 'a'],
            misses: 1,
            solved: true,
        });
    });
});

describe('who is at the board', () => {
    it('goes through the class in order, back to the first after the last, and survives a reload', () => {
        const { controller, storage } = setup();

        expect(controller.getView().studentId).toBeNull();

        controller.nextStudent();
        controller.nextStudent();
        expect(controller.getView().studentId).toBe('s2');

        controller.setStudent('s3');
        controller.nextStudent();
        expect(controller.getView().studentId).toBe('s1');

        expect(setup({ storage }).controller.getView().studentId).toBe('s1');

        controller.setStudent(null);
        expect(storage.data.has(boardStorageKey(GAME, 'student'))).toBe(false);
    });

    it('forgets a stored student who is no longer in the class', () => {
        const storage = memoryStorage();

        storage.setItem(boardStorageKey(GAME, 'student'), 'gone');

        expect(setup({ storage }).controller.getView().studentId).toBeNull();
    });
});

describe('fencing', () => {
    it('stops when /sync answers 409 board_replaced', async () => {
        const { controller, server, bridge, log } = setup();

        await controller.load();
        controller.start();
        server.replaceBoard();

        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        await settle();

        expect(controller.getView()).toMatchObject({
            phase: 'replaced',
            pending: 0,
        });

        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        expect(log).not.toContain('hit:b:correct');
    });

    it('stops when another tab of this browser claims the same game', async () => {
        let deliver: (message: unknown) => void = () => {};
        const channel: TabChannel = {
            post: vi.fn(),
            listen: (listener) => {
                deliver = listener;
            },
            close: vi.fn(),
        };
        const { controller } = setup({ channel });

        await controller.load();
        controller.start();

        expect(channel.post).toHaveBeenCalledWith({
            type: 'claimed',
            game: GAME,
            epoch: 5,
        });

        deliver({ type: 'claimed', game: 'other', epoch: 9 });
        expect(controller.getView().phase).toBe('playing');

        deliver({ type: 'claimed', game: GAME, epoch: 6 });
        expect(controller.getView().phase).toBe('replaced');
    });

    it('stops when another tab claimed after it while it was still loading', async () => {
        let deliver: (message: unknown) => void = () => {};
        const channel: TabChannel = {
            post: vi.fn(),
            listen: (listener) => {
                deliver = listener;
            },
            close: vi.fn(),
        };
        const { controller } = setup({ channel });

        // Two tabs reloaded at once: the other one's claim (6) lands while
        // this one (5) is still loading.
        const loading = controller.load();
        deliver({ type: 'claimed', game: GAME, epoch: 6 });
        await loading;

        expect(controller.getView().phase).toBe('replaced');
    });

    it('ignores an older claim heard while loading', async () => {
        let deliver: (message: unknown) => void = () => {};
        const channel: TabChannel = {
            post: vi.fn(),
            listen: (listener) => {
                deliver = listener;
            },
            close: vi.fn(),
        };
        const { controller } = setup({ channel });

        const loading = controller.load();
        deliver({ type: 'claimed', game: GAME, epoch: 3 });
        await loading;

        expect(controller.getView().phase).toBe('ready');
    });
});

describe('ending a game', () => {
    it('refuses while answers are unsaved, and ends once they are', async () => {
        const { controller, server, bridge } = setup();

        await controller.load();
        controller.start();
        server.goOffline();

        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        await settle();

        expect(controller.getView()).toMatchObject({
            pending: 1,
            retrying: true,
        });
        expect(await controller.end()).toBe(false);
        expect(controller.getView().notice).toBe('end_unsaved');
        expect(server.calls).not.toContain('status:finished');

        server.goOnline();
        controller.online();
        await settle();

        expect(controller.getView().pending).toBe(0);
        expect(await controller.end()).toBe(true);
        expect(controller.getView()).toMatchObject({
            phase: 'finished',
            status: 'finished',
        });
    });
});

describe('settling before the board goes (a guest board’s next game)', () => {
    it('sends what the outbox holds at once, without waiting for its backoff, and says what is left', async () => {
        const { controller, server, bridge } = setup();

        await controller.load();
        controller.start();
        server.goOffline();
        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        await settle();

        // Unreachable (the guest board no longer shows the game): it stays stored.
        expect(await controller.settle()).toBe(1);

        server.goOnline();

        expect(await controller.settle()).toBe(0);
        expect(server.synced).toHaveLength(1);
        expect(await controller.settle()).toBe(0);
        expect(server.synced).toHaveLength(1);
    });
});
