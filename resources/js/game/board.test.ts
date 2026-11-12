import { describe, expect, it, vi } from 'vite-plus/test';
import type { BoardControllerOptions, TabChannel } from './board';
import {
    BoardController,
    boardStorageKey,
    PRAISE_GAP_MS,
    SOLVED_CAP_MS,
} from './board';
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
            count: null,
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
            image: '/content/fluent/1F475.svg?v=2',
            count: null,
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

/** `praise`: the scene says a line after every solved round (§3 "Voice") and reports it due. */
function recordingScene(bridge: BoardBridge, praise = false) {
    const log: string[] = [];
    /** What the voice heard (§3 "Voice"): turns and the game's end. */
    const voice: string[] = [];
    const scene: BridgeScene = {
        showRound: (shown) => log.push(`show:${shown.id}`),
        applyHit: (effect: HitEffect) => {
            log.push(`hit:${effect.itemId}:${effect.status}`);

            if (praise && effect.solved) {
                bridge.praise({ roundId: effect.roundId, state: 'due' });
            }
        },
        setPaused: (paused) => log.push(paused ? 'pause' : 'play'),
        repeatPrompt: () => log.push('repeat'),
        startTurn: (studentId, fresh) =>
            voice.push(`turn:${studentId ?? 'class'}${fresh ? ':fresh' : ''}`),
        gameOver: (solved) => voice.push(`over:${solved}`),
    };

    return { scene, log, voice };
}

let ids = 0;

function setup(
    overrides: Partial<BoardControllerOptions> & {
        storage?: StorageLike & { data: Map<string, string> };
        server?: ReturnType<typeof fakeServer>;
        praise?: boolean;
    } = {},
) {
    const storage = overrides.storage ?? memoryStorage();
    const server = overrides.server ?? fakeServer();
    const timers = manualTimers();
    const bridge = new BoardBridge();
    const { scene, log, voice } = recordingScene(bridge, overrides.praise);

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

    return { controller, storage, server, timers, bridge, log, voice };
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

/*
 * The next round after a solved one (§4, §3 "Voice"): it waits for the
 * effect (1.6 s) and for the praise line the scene reports due, then a
 * short gap; never longer than the cap; held while paused.
 */
describe('the next round', () => {
    const shown = (log: string[]) =>
        log.filter((entry) => entry.startsWith('show:'));
    const praiseOver = (bridge: BoardBridge, roundId = 'round-0') =>
        bridge.praise({ roundId, state: 'over' });

    it('waits for a praise line longer than the effect, and a gap after it', async () => {
        const { controller, timers, bridge, log } = setup({ praise: true });

        await controller.load();
        controller.start();
        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });

        timers.fire(1600);
        expect(shown(log)).toEqual(['show:round-0']);

        praiseOver(bridge);
        expect(shown(log)).toEqual(['show:round-0']);

        timers.fire(PRAISE_GAP_MS);
        expect(shown(log)).toEqual(['show:round-0', 'show:round-1']);
        // The cap goes with the wait.
        expect(timers.pending.map((timer) => timer.ms)).not.toContain(
            SOLVED_CAP_MS,
        );
    });

    it('comes after the 1.6 s effect when no line is due, or the line was over sooner', async () => {
        const silent = setup();

        await silent.controller.load();
        silent.controller.start();
        silent.bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        silent.timers.fire(1600);

        expect(shown(silent.log)).toEqual(['show:round-0', 'show:round-1']);

        const short = setup({ praise: true });

        await short.controller.load();
        short.controller.start();
        short.bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        praiseOver(short.bridge);
        short.timers.fire(PRAISE_GAP_MS);

        expect(shown(short.log)).toEqual(['show:round-0']);

        short.timers.fire(1600);
        expect(shown(short.log)).toEqual(['show:round-0', 'show:round-1']);
    });

    it('stops waiting for a line that never ends at the cap', async () => {
        const { controller, timers, bridge, log } = setup({ praise: true });

        await controller.load();
        controller.start();
        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        timers.fire(1600);
        expect(shown(log)).toEqual(['show:round-0']);

        timers.fire(SOLVED_CAP_MS);
        expect(shown(log)).toEqual(['show:round-0', 'show:round-1']);

        // The line's end, late, moves nothing on.
        praiseOver(bridge);
        timers.fire(PRAISE_GAP_MS);
        expect(shown(log)).toEqual(['show:round-0', 'show:round-1']);
    });

    it('is held by a pause during the wait and comes on resume', async () => {
        const { controller, timers, bridge, log } = setup({ praise: true });

        await controller.load();
        controller.start();
        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        await controller.pause();

        timers.fire(1600);
        praiseOver(bridge);
        timers.fire(PRAISE_GAP_MS);
        timers.fire(SOLVED_CAP_MS);
        expect(shown(log)).toEqual(['show:round-0']);

        await controller.resume();
        expect(shown(log)).toEqual(['show:round-0', 'show:round-1']);
    });

    it('is cancelled when the game is ended during the wait', async () => {
        const { controller, timers, bridge, log, voice } = setup({
            praise: true,
        });

        await controller.load();
        controller.start();
        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        expect(await controller.end()).toBe(true);
        expect(timers.pending.map((timer) => timer.ms)).not.toContain(
            SOLVED_CAP_MS,
        );

        praiseOver(bridge);
        timers.fire(PRAISE_GAP_MS);
        timers.fire(1600);

        expect(shown(log)).toEqual(['show:round-0']);
        expect(controller.getView().phase).toBe('finished');
        expect(voice).toEqual(['turn:class:fresh', 'over:1']);
    });

    it('shows the result after the last round once its line is over, then says the end', async () => {
        const { controller, timers, bridge, voice } = setup({ praise: true });

        await controller.load();
        controller.start();
        controller.skip();
        controller.skip();
        bridge.itemTouched({ roundId: 'round-2', itemId: 'd' });
        timers.fire(1600);

        expect(controller.getView().phase).toBe('playing');

        praiseOver(bridge, 'round-2');
        timers.fire(PRAISE_GAP_MS);

        expect(controller.getView().phase).toBe('finished');
        expect(voice).toEqual(['turn:class:fresh', 'over:1']);
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
            repeatPrompt: () => {},
            startTurn: () => {},
            gameOver: () => {},
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

/*
 * The voice's turns (§3 "Voice", ADR 016 decision 6): «Başla» starts one
 * (fresh when nothing is answered, for a home game's greeting), and so
 * does every change of the student at the board during play — from the
 * board, «Növbəti» or the phone; a game that ends under play says its end.
 */
describe('turns of the voice', () => {
    it('starts a turn on «Başla», fresh only with nothing answered', async () => {
        const { controller, voice } = setup();

        await controller.load();
        controller.setStudent('s2');
        expect(voice).toEqual([]);

        controller.start();
        expect(voice).toEqual(['turn:s2:fresh']);

        const resumed = fakeServer([
            {
                round_id: 'round-0',
                hit_item_ids: ['b'],
                misses: 0,
                solved: true,
                skipped: false,
            },
        ]);
        const again = setup({ server: resumed });

        await again.controller.load();
        again.controller.start();
        expect(again.voice).toEqual(['turn:class']);
    });

    it('starts a new turn whenever another student comes to the board during play, never for the same one', async () => {
        const { controller, voice } = setup();

        await controller.load();
        controller.start();
        controller.setStudent('s1');
        controller.setStudent('s1');
        controller.nextStudent();
        controller.setStudent(null);

        expect(voice).toEqual([
            'turn:class:fresh',
            'turn:s1',
            'turn:s2',
            'turn:class',
        ]);
    });

    it('says the end with the solved rounds once the last round is over, and not for a game opened finished', async () => {
        const { controller, bridge, timers, voice } = setup();

        await controller.load();
        controller.start();
        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        timers.fire(1600);
        controller.skip();
        bridge.itemTouched({ roundId: 'round-2', itemId: 'd' });
        timers.fire(1600);

        expect(controller.getView().phase).toBe('finished');
        expect(voice).toEqual(['turn:class:fresh', 'over:2']);

        const done = fakeServer(
            ROUNDS.map((r) => ({
                round_id: r.id,
                hit_item_ids: [r.solution.item_ids[0]],
                misses: 0,
                solved: true,
                skipped: false,
            })),
        );
        const opened = setup({ server: done });

        await opened.controller.load();
        expect(opened.controller.getView().phase).toBe('finished');
        expect(opened.voice).toEqual([]);
    });

    it('says the end when the game is ended under play', async () => {
        const { controller, voice } = setup();

        await controller.load();
        controller.start();
        expect(await controller.end()).toBe(true);
        expect(voice).toEqual(['turn:class:fresh', 'over:0']);
    });
});

describe('a home board (architecture §4 "At home")', () => {
    it('queues hits with no student, whatever asks for one', async () => {
        const { controller, server, bridge } = setup({ studentIds: [] });

        await controller.load();
        controller.start();
        // The phone of a class game would send these; a home game has no students.
        controller.setStudent('s1');
        controller.nextStudent();
        expect(controller.getView().studentId).toBeNull();

        bridge.itemTouched({ roundId: 'round-0', itemId: 'a' });
        bridge.itemTouched({ roundId: 'round-0', itemId: 'b' });
        await settle();

        const events = server.synced.flatMap((request) => request.events);

        expect(events).toHaveLength(2);
        expect(events.map((event) => event.type)).toEqual(['hit', 'hit']);
        for (const event of events) {
            expect(event).toMatchObject({ student_id: null, source: 'board' });
        }
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
