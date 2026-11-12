import { beforeEach, describe, expect, it, vi } from 'vite-plus/test';
import { BoardBridge } from '../bridge';
import { Round } from '../contract';
import type { RoundProgress } from '../contract';
import type {
    ItemDrawing,
    ItemStyle,
    ItemView,
    SlotStyle,
    SlotView,
} from '../styles/types';
import type { NameClips } from '../name-voice';
import type { BoardSounds, SceneOptions } from './board-scene';
import { BuildWordScene } from './build-word';
import { FindScene } from './find';
import type { BoardVoice, VoicePlayers } from '../voice';
import { NAME_GAP_MS, NO_PLAYERS, SILENT } from '../voice';

// The scenes run on a stand-in for Phaser: a scene clock, a sound manager
// that records what plays, and objects that take any drawing call.
vi.mock('phaser', () => ({
    default: {
        Scene: class {},
        Math: { Linear: (a: number, b: number, t: number) => a + (b - a) * t },
        Display: {
            Color: {
                IntegerToRGB: (colour: number) => ({
                    r: (colour >> 16) & 0xff,
                    g: (colour >> 8) & 0xff,
                    b: colour & 0xff,
                }),
                GetColor: (r: number, g: number, b: number) =>
                    (r << 16) | (g << 8) | b,
            },
        },
        Loader: {
            Events: {
                COMPLETE: 'complete',
                FILE_KEY_COMPLETE: 'key-',
                FILE_LOAD_ERROR: 'loaderror',
            },
        },
        Scenes: { Events: { SHUTDOWN: 'shutdown' } },
        Sound: { Events: { COMPLETE: 'complete' } },
    },
}));
vi.mock('../styles/registry', () => ({ effect: () => ({ play: () => {} }) }));

/*
 * A pack's `arrive` and `leave` sounds (docs/architecture.md §3 "Sounds"):
 * once per round each, never per wagon, in `find` and in the build
 * scenes; nothing for a pack without them; none for a round shown already
 * solved; paused with the board.
 */

const fixtures = import.meta.glob<unknown>(
    '../../../../tests/Fixtures/contract/round.*.json',
    { eager: true, import: 'default' },
);

function fixture(name: string, id: string): Round {
    const found = Object.entries(fixtures).find(([path]) =>
        path.endsWith(`/round.${name}.json`),
    );

    if (found === undefined) {
        throw new Error(`No fixture round.${name}.json`);
    }

    return { ...Round.parse(found[1]), id };
}

function open(round: Round): RoundProgress {
    return {
        round_id: round.id,
        hit_item_ids: [],
        misses: 0,
        solved: false,
        skipped: false,
    };
}

/** Takes any call and any property, as a Phaser game object would. */
const anything: unknown = new Proxy(function () {}, {
    get: (_target, key) => {
        if (key === Symbol.toPrimitive) {
            return () => 100;
        }

        return key === 'then' ? undefined : anything;
    },
    apply: () => anything,
});

/** The scene clock: `delayedCall` runs as time passes, never while paused. */
class Clock {
    now = 0;
    paused = false;
    private calls: { at: number; run: () => void }[] = [];

    delayedCall(delay: number, run: () => void): void {
        this.calls.push({ at: this.now + delay, run });
    }

    advance(ms: number): void {
        if (this.paused) {
            return;
        }

        const end = this.now + ms;

        for (;;) {
            const due = this.calls
                .filter((call) => call.at <= end)
                .sort((a, b) => a.at - b.at)[0];

            if (due === undefined || this.paused) {
                break;
            }

            this.calls.splice(this.calls.indexOf(due), 1);
            this.now = due.at;
            due.run();
        }

        if (!this.paused) {
            this.now = end;
        }
    }
}

/** How long each sound lasts, as the real files roughly do. */
const LENGTHS: Record<keyof BoardSounds, number> = {
    hit: 200,
    miss: 560,
    solved: 1290,
    arrive: 1450,
    leave: 1250,
};

class FakeSound {
    isPaused = false;
    private since: number;
    private left: number;

    constructor(
        readonly key: string,
        private readonly clock: Clock,
    ) {
        this.since = clock.now;
        this.left = LENGTHS[key as keyof BoardSounds];
    }

    get isPlaying(): boolean {
        return !this.isPaused && this.clock.now - this.since < this.left;
    }

    pause(): boolean {
        if (!this.isPlaying) {
            return false;
        }

        this.left -= this.clock.now - this.since;
        this.isPaused = true;

        return true;
    }

    resume(): boolean {
        if (!this.isPaused) {
            return false;
        }

        this.isPaused = false;
        this.since = this.clock.now;

        return true;
    }

    /** `complete`, when what is left of it has played. */
    once(_event: string, run: () => void): void {
        this.clock.delayedCall(this.left - (this.clock.now - this.since), run);
    }
}

/** How long a voice clip lasts here. */
const CLIP_MS = 1000;

/** A clip of the voice (`sound.add()`): it says its URL and then completes. */
class FakeClip {
    isPaused = false;
    stopped = false;
    private done: (() => void)[] = [];

    constructor(
        readonly url: string,
        private readonly clock: Clock,
        private readonly said: string[],
    ) {}

    once(_event: string, run: () => void): void {
        this.done.push(run);
    }

    play(): boolean {
        this.said.push(this.url);
        this.clock.delayedCall(CLIP_MS, () => this.complete());

        return true;
    }

    /** It has been said to the end (audio plays on while the scene clock stands). */
    complete(): void {
        if (!this.stopped) {
            this.done.splice(0).forEach((run) => run());
        }
    }

    pause(): boolean {
        this.isPaused = true;

        return true;
    }

    resume(): boolean {
        this.isPaused = false;

        return true;
    }

    stop(): boolean {
        this.stopped = true;

        return true;
    }

    destroy(): void {}
}

class FakeSounds {
    readonly played: string[] = [];
    readonly all: FakeSound[] = [];
    readonly said: string[] = [];
    readonly clips: FakeClip[] = [];

    constructor(private readonly clock: Clock) {}

    add(key: string): FakeClip {
        const clip = new FakeClip(
            key.replace(/^voice:/, ''),
            this.clock,
            this.said,
        );

        this.clips.push(clip);

        return clip;
    }

    getAll(key: string): FakeSound[] {
        return this.all.filter(
            (sound) => sound.key === key && (sound.isPlaying || sound.isPaused),
        );
    }

    play(key: string): boolean {
        this.played.push(key);
        this.all.push(new FakeSound(key, this.clock));

        return true;
    }
}

type Moves = { enter: string[]; leave: string[] };

function itemStyle(moves: Moves): ItemStyle {
    const view = (itemId: string): ItemView => ({
        itemId,
        colour: 0,
        onTouch: () => {},
        centre: () => ({ x: 0, y: 0 }),
        size: () => ({ width: 100, height: 80 }),
        enter: () => moves.enter.push(itemId),
        wobble: () => {},
        pop: () => {},
        lift: () => {},
        leave: () => moves.leave.push(itemId),
        destroy: () => {},
    });

    return {
        stagger: 0,
        layout: (count) => ({
            width: 100,
            height: 80,
            spots: Array.from({ length: count }, (_, index) => ({
                x: 200 + index * 150,
                y: 600,
            })),
        }),
        draw: () => anything as ItemDrawing,
        create: (_scene, item) => view(item.id),
    };
}

function slotStyle(moves: Moves): SlotStyle {
    return {
        create: (): SlotView => ({
            spot: (index) => ({
                x: 300 + index * 200,
                y: 300,
                width: 160,
                height: 120,
            }),
            enter: () => moves.enter.push('slots'),
            progress: () => {},
            complete: () => {},
            leave: () => moves.leave.push('slots'),
            destroy: () => {},
        }),
    };
}

const TRAIN: BoardSounds = {
    hit: '/content/sounds/pop.mp3',
    miss: '/content/sounds/pluck-down.mp3',
    solved: '/content/sounds/bells.mp3',
    arrive: '/content/sounds/train-arrive.mp3',
    leave: '/content/sounds/train-leave.mp3',
};

const BALLOONS: BoardSounds = { ...TRAIN, arrive: null, leave: null };

type Board<S> = { scene: S; clock: Clock; sounds: FakeSounds; moves: Moves };

function mount<S extends FindScene | BuildWordScene>(
    make: (options: SceneOptions) => S,
    packSounds: BoardSounds,
    voice: BoardVoice = SILENT,
    players: VoicePlayers = NO_PLAYERS,
    names: NameClips | null = null,
): Board<S> {
    const moves: Moves = { enter: [], leave: [] };
    const style = itemStyle(moves);
    const scene = make({
        bridge: new BoardBridge(),
        style,
        styleOf: () => style,
        slots: slotStyle(moves),
        palette: [0x3b82c4, 0xe85d4a],
        sounds: packSounds,
        voice,
        players,
        names,
        figures: [],
        pictures: {},
        onReady: () => {},
    });
    const clock = new Clock();
    const sounds = new FakeSounds(clock);
    const loaded = new Set<string>();
    const queued: string[] = [];
    const listeners = new Map<string, Set<() => void>>();

    Object.assign(scene, {
        time: clock,
        sound: sounds,
        cache: {
            audio: {
                exists: (key: string) => loaded.has(key),
                get: () => undefined,
                add: (key: string) => loaded.add(key),
            },
        },
        load: {
            audio: (key: string) => {
                loaded.add(key);
                queued.push(key);
            },
            svg: () => {},
            once: () => {},
            on: (event: string, run: () => void) => {
                listeners.set(
                    event,
                    new Set([...(listeners.get(event) ?? []), run]),
                );
            },
            off: (event: string, run: () => void) => {
                listeners.get(event)?.delete(run);
            },
            // Every file queued loads at once.
            start: () => {
                for (const key of queued.splice(0)) {
                    // A Set may lose a listener while it is iterated.
                    for (const run of listeners.get(`key-audio-${key}`) ?? []) {
                        run();
                    }
                }
            },
        },
        sys: { isActive: () => true },
        textures: { exists: () => true },
        input: { enabled: true },
        add: anything,
        tweens: anything,
    });
    scene.preload();

    return { scene, clock, sounds, moves };
}

const count = (played: string[], key: string) =>
    played.filter((sound) => sound === key).length;

describe('find', () => {
    const first = fixture('find-word', '01JC8Z6V8Q4B2X5N7M9P3R6T0A');
    const second = fixture('find-word', '01JC8Z6V8Q4B2X5N7M9P3R6T0B');
    let board: Board<FindScene>;

    beforeEach(() => {
        board = mount((options) => new FindScene(options), TRAIN);
    });

    it('plays one arrive as a round rolls in and one leave as the rest of it go when solved', () => {
        const { scene, clock, sounds, moves } = board;

        scene.showRound(first, open(first));
        clock.advance(240);

        expect(sounds.played).toEqual([]);

        clock.advance(20);

        // Four wagons, one train: one sound.
        expect(moves.enter).toHaveLength(4);
        expect(sounds.played).toEqual(['arrive']);

        clock.advance(2000);
        scene.applyHit({
            roundId: first.id,
            itemId: 'd',
            status: 'correct',
            solved: true,
        });
        clock.advance(400);

        expect(moves.leave).toHaveLength(3);
        expect(sounds.played).toEqual(['arrive', 'hit', 'solved', 'leave']);

        // The next round: its items are new, nothing is left to go.
        clock.advance(1200);
        scene.showRound(second, open(second));
        clock.advance(2000);

        expect(count(sounds.played, 'arrive')).toBe(2);
        expect(count(sounds.played, 'leave')).toBe(1);
    });

    it('plays one leave and one arrive when a new round replaces one still on the board', () => {
        const { scene, clock, sounds } = board;

        scene.showRound(first, open(first));
        clock.advance(2000);
        scene.showRound(second, open(second));
        clock.advance(2000);

        expect(sounds.played).toEqual(['arrive', 'leave', 'arrive']);
    });

    it('plays no arrive for a round shown already solved', () => {
        const { scene, clock, sounds } = board;

        scene.showRound(first, {
            ...open(first),
            hit_item_ids: ['d'],
            solved: true,
        });
        clock.advance(2000);

        expect(sounds.played).toEqual([]);
    });

    it('plays neither for a pack without them', () => {
        const { scene, clock, sounds } = mount(
            (options) => new FindScene(options),
            BALLOONS,
        );

        scene.showRound(first, open(first));
        clock.advance(2000);
        scene.applyHit({
            roundId: first.id,
            itemId: 'd',
            status: 'correct',
            solved: true,
        });
        clock.advance(2000);
        scene.showRound(second, open(second));
        clock.advance(2000);

        expect(sounds.played).toEqual(['hit', 'solved']);
    });

    it('waits and pauses with the board', () => {
        const { scene, clock, sounds } = board;

        scene.showRound(first, open(first));
        scene.setPaused(true);
        clock.advance(1000);

        expect(sounds.played).toEqual([]);

        scene.setPaused(false);
        clock.advance(300);

        expect(sounds.played).toEqual(['arrive']);

        const [arrive] = sounds.getAll('arrive');

        scene.setPaused(true);

        expect(arrive.isPaused).toBe(true);

        scene.setPaused(false);

        expect(arrive.isPlaying).toBe(true);
    });
});

describe('build_word', () => {
    const first = fixture(
        'build-word-reused-tile',
        '01JC8Z6V8Q4B2X5N7M9P3R6T0A',
    );
    const second = fixture(
        'build-word-reused-tile',
        '01JC8Z6V8Q4B2X5N7M9P3R6T0B',
    );

    it('plays one arrive for the slot train and its tiles, and one leave when the row goes', () => {
        const { scene, clock, sounds, moves } = mount(
            (options) => new BuildWordScene(options),
            TRAIN,
        );

        scene.showRound(first, open(first));
        clock.advance(2500);

        expect(moves.enter).toEqual(['slots', 'a', 'b', 'c']);
        expect(sounds.played).toEqual(['arrive']);

        scene.applyHit({
            roundId: first.id,
            itemId: 'a',
            status: 'correct',
            solved: false,
        });
        scene.applyHit({
            roundId: first.id,
            itemId: 'a',
            status: 'correct',
            solved: true,
        });
        clock.advance(2000);

        // The tiles left over go at once; the slot train waits for the next round.
        expect(moves.leave).toEqual(['a', 'b', 'c']);
        expect(count(sounds.played, 'leave')).toBe(0);

        scene.showRound(second, open(second));
        clock.advance(0);

        expect(sounds.played.at(-1)).toBe('leave');

        clock.advance(2500);

        expect(moves.leave).toEqual(['a', 'b', 'c', 'slots']);
        expect(count(sounds.played, 'arrive')).toBe(2);
        expect(count(sounds.played, 'leave')).toBe(1);
    });

    it('plays no arrive for a round shown already solved', () => {
        const { scene, clock, sounds } = mount(
            (options) => new BuildWordScene(options),
            TRAIN,
        );

        scene.showRound(first, {
            ...open(first),
            hit_item_ids: ['a', 'a'],
            solved: true,
        });
        clock.advance(2500);

        expect(sounds.played).toEqual([]);
    });
});

/*
 * The voice in the scenes (docs/architecture.md §3 "Voice", ADR 015): the
 * prompt once the items are in, after the pack's `arrive` sound; praise
 * after the `solved` sound, waiting for a prompt still being said; the
 * retry phrase once a round, into silence; paused with the board.
 */
describe('the voice', () => {
    const VOICE: BoardVoice = {
        prompt: true,
        praise: ['/content/voice/praise.mp3'],
        retry: ['/content/voice/retry.mp3'],
        names: true,
        greeting: '/content/voice/salam.mp3',
        finish: '/content/voice/ela-oynadin.mp3',
        finish_class: '/content/voice/ela-oynadiniz.mp3',
    };
    const find = fixture('find-word', '01JC8Z6V8Q4B2X5N7M9P3R6T0A');
    const rabbit = {
        ...find,
        prompt: { ...find.prompt, audio: '/content/voice/rabbit.mp3' },
    };
    const next = {
        ...fixture('find-word', '01JC8Z6V8Q4B2X5N7M9P3R6T0B'),
        prompt: { ...find.prompt, audio: '/content/voice/cat.mp3' },
    };

    it('says the prompt once the items are in, and after the train has arrived', () => {
        const balloons = mount(
            (options) => new FindScene(options),
            BALLOONS,
            VOICE,
        );

        balloons.scene.showRound(rabbit, open(rabbit));
        balloons.clock.advance(849);

        expect(balloons.sounds.said).toEqual([]);

        balloons.clock.advance(1);

        expect(balloons.sounds.said).toEqual(['/content/voice/rabbit.mp3']);

        // The train chugs in until 250 + 1450 ms: the prompt waits for it.
        const train = mount((options) => new FindScene(options), TRAIN, VOICE);

        train.scene.showRound(rabbit, open(rabbit));
        train.clock.advance(1699);

        expect(train.sounds.said).toEqual([]);

        train.clock.advance(1);

        expect(train.sounds.said).toEqual(['/content/voice/rabbit.mp3']);
    });

    it('says nothing for a round without a clip, a round shown solved, or with the prompt off', () => {
        const board = mount(
            (options) => new FindScene(options),
            BALLOONS,
            VOICE,
        );

        board.scene.showRound(find, open(find));
        board.clock.advance(2000);
        board.scene.showRound(rabbit, { ...open(rabbit), solved: true });
        board.clock.advance(2000);

        expect(board.sounds.said).toEqual([]);

        const off = mount((options) => new FindScene(options), BALLOONS, {
            ...VOICE,
            prompt: false,
        });

        off.scene.showRound(rabbit, open(rabbit));
        off.clock.advance(2000);
        off.scene.repeatPrompt();

        expect(off.sounds.said).toEqual([]);
    });

    it('praises a solved round after the solved sound, and the next prompt waits for the praise', () => {
        const { scene, clock, sounds } = mount(
            (options) => new FindScene(options),
            BALLOONS,
            VOICE,
        );

        scene.showRound(rabbit, open(rabbit));
        clock.advance(2000);
        scene.applyHit({
            roundId: rabbit.id,
            itemId: 'd',
            status: 'correct',
            solved: true,
        });
        clock.advance(340);

        expect(sounds.said).toEqual([
            '/content/voice/rabbit.mp3',
            '/content/voice/praise.mp3',
        ]);

        // The next round comes in while the praise is said.
        scene.showRound(next, open(next));
        clock.advance(850);

        expect(sounds.said).toHaveLength(2);

        clock.advance(200);

        expect(sounds.said.at(-1)).toBe('/content/voice/cat.mp3');
    });

    it('says the retry phrase once a round, only into silence', () => {
        const { scene, clock, sounds } = mount(
            (options) => new FindScene(options),
            BALLOONS,
            VOICE,
        );
        const miss = {
            roundId: rabbit.id,
            itemId: 'a',
            status: 'miss' as const,
            solved: false,
        };

        scene.showRound(rabbit, open(rabbit));
        clock.advance(2000);
        scene.applyHit(miss);
        clock.advance(100);
        scene.applyHit(miss);
        clock.advance(2000);

        expect(sounds.said).toEqual([
            '/content/voice/rabbit.mp3',
            '/content/voice/retry.mp3',
        ]);

        // A miss while the prompt is being said: no retry over it.
        scene.showRound(next, open(next));
        clock.advance(900);
        scene.applyHit({ ...miss, roundId: next.id });
        clock.advance(2000);

        expect(sounds.said).toEqual([
            '/content/voice/rabbit.mp3',
            '/content/voice/retry.mp3',
            '/content/voice/cat.mp3',
        ]);
    });

    it('says the prompt again with 🔊, cutting in, and pauses with the board', () => {
        const { scene, clock, sounds } = mount(
            (options) => new FindScene(options),
            BALLOONS,
            VOICE,
        );

        scene.showRound(rabbit, open(rabbit));
        clock.advance(900);
        scene.setPaused(true);

        expect(sounds.clips.at(-1)?.isPaused).toBe(true);

        scene.setPaused(false);
        scene.repeatPrompt();

        expect(sounds.clips[0].stopped).toBe(true);
        expect(sounds.said).toEqual([
            '/content/voice/rabbit.mp3',
            '/content/voice/rabbit.mp3',
        ]);
    });

    it("says a build round's prompt once its tiles are in", () => {
        const build = fixture('build-syllable', '01JC8Z6V8Q4B2X5N7M9P3R6T0C');
        const round = {
            ...build,
            prompt: { ...build.prompt, audio: '/content/voice/ba.mp3' },
        };
        const { scene, clock, sounds } = mount(
            (options) => new BuildWordScene(options),
            BALLOONS,
            VOICE,
        );

        scene.showRound(round, open(round));
        clock.advance(949);

        expect(sounds.said).toEqual([]);

        clock.advance(1);

        expect(sounds.said).toEqual(['/content/voice/ba.mp3']);
    });

    /*
     * A player's name (ADR 016): before the praise, «Salam!» and the end,
     * `NAME_GAP_MS` apart, on the same channel; a prompt waits
     * for the whole line, a name not fetched yet is left out and the phrase
     * said alone, and the end is said while the board stands still.
     */
    describe('a name', () => {
        const players: VoicePlayers = {
            child: null,
            students: [
                { id: 's1', name_audio: '/games/g1/players/s1/voice' },
                { id: 's2', name_audio: null },
            ],
        };
        const home: VoicePlayers = {
            child: { id: 'c1', name_audio: '/games/g1/players/c1/voice' },
            students: [],
        };

        /** Name clips that have arrived, by player; what was asked for. */
        function clips(arrived: string[]) {
            const asked: string[] = [];
            const names: NameClips = {
                load: (player) => asked.push(player.id),
                get: (id) =>
                    arrived.includes(id) ? ({} as AudioBuffer) : null,
                dispose: () => {},
            };

            return { names, asked };
        }

        const solve = {
            roundId: rabbit.id,
            itemId: 'd',
            status: 'correct' as const,
            solved: true,
        };

        it('comes before the praise after a pause, and the next prompt waits for both', () => {
            const { names, asked } = clips(['s1']);
            const { scene, clock, sounds } = mount(
                (options) => new FindScene(options),
                BALLOONS,
                VOICE,
                players,
                names,
            );

            scene.startTurn('s1', true);
            expect(asked).toEqual(['s1']);

            scene.showRound(rabbit, open(rabbit));
            clock.advance(2000);
            scene.applyHit(solve);
            // The praise's line: after the solved sound (260 ms, and its length, here none, + 80 ms).
            clock.advance(340);
            expect(sounds.said.slice(1)).toEqual(['name:s1']);

            // The next round comes in while the name is said.
            scene.showRound(next, open(next));
            clock.advance(1000 + NAME_GAP_MS - 1);
            expect(sounds.said.slice(1)).toEqual(['name:s1']);

            clock.advance(1);
            expect(sounds.said.slice(1)).toEqual([
                'name:s1',
                '/content/voice/praise.mp3',
            ]);

            // The prompt, due 850 ms after the round came in, waits for the praise.
            clock.advance(999);
            expect(sounds.said).toHaveLength(3);

            clock.advance(1);
            expect(sounds.said.at(-1)).toBe('/content/voice/cat.mp3');
        });

        it('leaves out a name whose clip has not arrived, and says the praise alone at once', () => {
            const { names } = clips([]);
            const { scene, clock, sounds } = mount(
                (options) => new FindScene(options),
                BALLOONS,
                VOICE,
                players,
                names,
            );

            scene.startTurn('s1', false);
            scene.showRound(rabbit, open(rabbit));
            clock.advance(2000);
            scene.applyHit(solve);
            clock.advance(340);

            expect(sounds.said).toEqual([
                '/content/voice/rabbit.mp3',
                '/content/voice/praise.mp3',
            ]);

            clock.advance(5000);
            expect(sounds.said).toHaveLength(2);
        });

        it("greets a home game with the child's name and then «Salam!», before its first prompt, which waits", () => {
            const { names, asked } = clips(['c1']);
            const { scene, clock, sounds } = mount(
                (options) => new FindScene(options),
                BALLOONS,
                VOICE,
                home,
                names,
            );

            // The child's clip is fetched with the scene.
            expect(asked).toEqual(['c1']);

            scene.startTurn(null, true);
            scene.showRound(rabbit, open(rabbit));
            expect(sounds.said).toEqual(['name:c1']);

            clock.advance(1000 + NAME_GAP_MS);
            expect(sounds.said).toEqual([
                'name:c1',
                '/content/voice/salam.mp3',
            ]);

            clock.advance(1000);
            expect(sounds.said.at(-1)).toBe('/content/voice/rabbit.mp3');
        });

        it('is cut with its praise by 🔊, which says the prompt at once', () => {
            const { names } = clips(['s1']);
            const { scene, clock, sounds } = mount(
                (options) => new FindScene(options),
                BALLOONS,
                VOICE,
                players,
                names,
            );

            scene.startTurn('s1', false);
            scene.showRound(rabbit, open(rabbit));
            clock.advance(2000);
            scene.applyHit(solve);
            clock.advance(340 + 500);
            scene.repeatPrompt();
            clock.advance(5000);

            expect(sounds.said).toEqual([
                '/content/voice/rabbit.mp3',
                'name:s1',
                '/content/voice/rabbit.mp3',
            ]);
        });

        it('ends the game with the name and then the phrase while the board stands still under the result', () => {
            vi.useFakeTimers();

            try {
                const { names } = clips(['c1']);
                const { scene, clock, sounds } = mount(
                    (options) => new FindScene(options),
                    BALLOONS,
                    VOICE,
                    home,
                    names,
                );

                scene.startTurn(null, false);
                scene.showRound(rabbit, open(rabbit));
                clock.advance(2000);
                scene.applyHit(solve);
                // The board finishes before the praise due after the solved
                // sound (a real one lasts about as long as the solved
                // effect): the praise gives way to the end.
                clock.advance(300);
                scene.setPaused(true);
                scene.gameOver(1);
                clock.advance(5000);

                expect(sounds.said.slice(1)).toEqual(['name:c1']);

                sounds.clips.at(-1)?.complete();
                vi.advanceTimersByTime(NAME_GAP_MS);

                expect(sounds.said.slice(1)).toEqual([
                    'name:c1',
                    '/content/voice/ela-oynadin.mp3',
                ]);
            } finally {
                vi.useRealTimers();
            }
        });

        it('says the name once when the game ends while a praise is saying it: the end follows it', () => {
            vi.useFakeTimers();

            try {
                const { names } = clips(['s1']);
                const { scene, clock, sounds } = mount(
                    (options) => new FindScene(options),
                    BALLOONS,
                    VOICE,
                    players,
                    names,
                );

                scene.startTurn('s1', false);
                scene.showRound(rabbit, open(rabbit));
                clock.advance(2000);
                scene.applyHit(solve);
                // The name before the praise is being said when the game ends.
                clock.advance(340 + 400);
                scene.setPaused(true);
                scene.gameOver(1);

                const name = sounds.clips.at(-1);

                expect(name?.isPaused).toBe(false);
                expect(sounds.said.at(-1)).toBe('name:s1');

                name?.complete();
                vi.advanceTimersByTime(NAME_GAP_MS);

                expect(sounds.said.slice(1)).toEqual([
                    'name:s1',
                    '/content/voice/ela-oynadin.mp3',
                ]);
            } finally {
                vi.useRealTimers();
            }
        });

        it('lets a praise being said end before the end, which then says the name first', () => {
            vi.useFakeTimers();

            try {
                const { names } = clips(['s1']);
                const { scene, clock, sounds } = mount(
                    (options) => new FindScene(options),
                    BALLOONS,
                    VOICE,
                    players,
                    names,
                );
                const nextSolve = { ...solve, roundId: next.id };

                scene.startTurn('s1', false);
                scene.showRound(rabbit, open(rabbit));
                clock.advance(2000);
                // The 1st solved round: «Aysel! … Afərin!», then the prompt.
                scene.applyHit(solve);
                scene.showRound(next, open(next));
                clock.advance(4000);
                // The 2nd: the praise alone, being said when the game ends.
                scene.applyHit(nextSolve);
                clock.advance(340 + 400);
                scene.setPaused(true);
                scene.gameOver(2);

                expect(sounds.said.slice(-2)).toEqual([
                    '/content/voice/cat.mp3',
                    '/content/voice/praise.mp3',
                ]);

                sounds.clips.at(-1)?.complete();
                sounds.clips.at(-1)?.complete();
                vi.advanceTimersByTime(NAME_GAP_MS);

                expect(sounds.said.slice(-3)).toEqual([
                    '/content/voice/praise.mp3',
                    'name:s1',
                    '/content/voice/ela-oynadin.mp3',
                ]);
            } finally {
                vi.useRealTimers();
            }
        });
    });
});
