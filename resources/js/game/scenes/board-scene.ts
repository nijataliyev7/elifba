/**
 * What every scene of the board shares (docs/architecture.md §4, "one
 * Phaser scene per mechanic"): the pack's sounds, the scenery drawn once,
 * the link to the bridge, pausing, the voice. The pack's backdrop is not drawn here:
 * it is the board page's background behind the transparent canvas
 * (`board-screen.tsx`, §8 "Backdrops"), so it reaches the screen's edges. Each mechanic's
 * scene draws its rounds on top: `find.ts` (and `count.ts` on it), and the
 * three build scenes on `build.ts`. A scene never judges, stores or sends
 * anything.
 *
 * Logical size 1920×1080; the stage scales it with `Scale.FIT`. The prompt
 * and everything else around the canvas belong to React.
 */
import Phaser from 'phaser';
import type { BoardBridge, BridgeScene, HitEffect } from '../bridge';
import type { Round, RoundProgress, Unit } from '../contract';
import type { Figure, Pictures } from '../figures';
import { chooseFigures, figureKey } from '../figures';
import { FIGURE_RASTER } from '../styles/figure';
import type { NameClips } from '../name-voice';
import type { ItemStyle, SlotStyle } from '../styles/types';
import type { BoardVoice, VoiceLine, VoicePlayers } from '../voice';
import { NAME_GAP_MS, VoiceCues } from '../voice';

export const BOARD_WIDTH = 1920;
export const BOARD_HEIGHT = 1080;

/**
 * The pack's sounds by moment (docs/architecture.md §3 "Sounds"), null
 * for none. `arrive` and `leave` go with a round coming in and going: a
 * scene plays each once per round because the pack has it, never because
 * of its item or slot style.
 */
export type BoardSounds = {
    hit: string | null;
    miss: string | null;
    solved: string | null;
    arrive: string | null;
    leave: string | null;
};

export type SceneOptions = {
    bridge: BoardBridge;
    /** The pack's item style, from `styles/registry.ts`. */
    style: ItemStyle;
    /**
     * The item style a round of this unit is drawn in (`stage.ts`): the
     * pack's, except the `picture` style for every round of the picture
     * unit (R5).
     */
    styleOf: (unit: Unit) => ItemStyle;
    /** The pack's slot style; a build scene needs one. */
    slots: SlotStyle | null;
    /** The pack palette as numbers (`0xE85D4A`). */
    palette: number[];
    sounds: BoardSounds;
    /** What the board says besides the sound effects (§3 "Voice"). */
    voice: BoardVoice;
    /** Who may be at the board, for the names the voice says (ADR 016). */
    players: VoicePlayers;
    /** The players' name clips, fetched by ids (`name-voice.ts`); null where the page cannot decode audio. */
    names: NameClips | null;
    /** The pack's figures (`pack.style.figures`), for `animal` and `fruit` items; empty for the rest. */
    figures: Figure[];
    /** The pictures that show a content key (`pack.pictures`), for the same items. */
    pictures: Pictures;
    /** The scene is ready and connected to the bridge. */
    onReady: () => void;
};

const FALLBACK_COLOUR = 0x3b82c4;

/** The sounds that go with motion, paused with it (`setPaused()`). */
const MOTION_SOUNDS = ['arrive', 'leave'] as const;

/**
 * How the voice waits for a free channel (§3 "Voice"): a prompt, praise,
 * the greeting or the end waits for the line being said; the retry phrase
 * is said only into silence; 🔊 cuts in.
 */
type VoiceTurn = 'queue' | 'idle' | 'now';

/** One clip on the voice's channel: a content file, or a player's name (`name-voice.ts`). */
type Clip = { url: string } | { player: string };

/** What to call once a line is over: said, dropped or cut; null when nobody waits for it. */
type Over = (() => void) | null;

/** A line waiting for the channel. */
type Waiting = { clips: Clip[]; over: Over };

/** A line being said: a name and a phrase, `NAME_GAP_MS` apart. */
type Utterance = {
    /** The clips still to say after the one being said. */
    rest: Clip[];
    /** The clip being said, or said last in the pause after it. */
    clip: Clip | null;
    /** Its sound; null while it loads or in the pause after it. */
    sound: Phaser.Sound.BaseSound | null;
    state: 'loading' | 'playing' | 'gap';
    over: Over;
};

/** The voice's texture-cache keys, apart from the pack's sound keys. */
function voiceKey(url: string): string {
    return `voice:${url}`;
}

/** A name clip's cache key: the player's id, never the name. */
function nameKey(playerId: string): string {
    return `name:${playerId}`;
}

/** A line's clips: the name, when there is one, then the phrase. */
function clipsOf(line: VoiceLine): Clip[] {
    const phrase: Clip = { url: line.phrase };

    return line.name === null ? [phrase] : [{ player: line.name.id }, phrase];
}

/** The clip a miss or solved sound is followed by starts this long after it ends. */
const VOICE_GAP_MS = 80;

export abstract class BoardScene extends Phaser.Scene implements BridgeScene {
    private disconnect: (() => void) | null = null;
    private readonly cues: VoiceCues;
    /** The line being said, and the one waiting for it. */
    private speaking: Utterance | null = null;
    private waiting: Waiting | null = null;
    private readonly loading = new Map<string, ((ok: boolean) => void)[]>();
    /** The game ended on this board: the canvas stands frozen under the result. */
    private over = false;

    constructor(
        key: string,
        protected readonly options: SceneOptions,
    ) {
        super({ key });
        this.cues = new VoiceCues(options.voice, options.players);
    }

    preload(): void {
        for (const [key, url] of Object.entries(this.options.sounds)) {
            if (url !== null) {
                this.load.audio(key, url);
            }
        }

        for (const url of this.cues.phrases()) {
            this.load.audio(voiceKey(url), url);
        }

        // A home game's child is at the board from the start.
        for (const player of this.cues.namesUpFront()) {
            this.options.names?.load(player);
        }

        // Pictures behind items are SVGs with only a size of their own
        // (Fluent's 32×32, or own art): rasterised at the largest size an
        // item draws them, so they are sharp and the build copies reuse them.
        const urls = new Set([
            ...this.options.figures.map((figure) => figure.url),
            ...Object.values(this.options.pictures).flat(),
        ]);

        for (const url of urls) {
            this.load.svg(figureKey(url), url, {
                width: FIGURE_RASTER,
                height: FIGURE_RASTER,
            });
        }
    }

    create(): void {
        this.scenery();

        this.disconnect = this.options.bridge.connect(this);
        this.events.once(Phaser.Scenes.Events.SHUTDOWN, () => {
            this.disconnect?.();
        });
        this.options.onReady();
    }

    // The bridge, in.

    abstract showRound(round: Round, progress: RoundProgress): void;

    abstract applyHit(effect: HitEffect): void;

    /** 🔊: the prompt of the round on the board again, at once. */
    repeatPrompt(): void {
        const url = this.cues.repeat(this.currentRound());

        if (url !== null) {
            this.say([{ url }], 'now');
        }
    }

    /**
     * A turn starts (ADR 016 decision 6): the count of its solved rounds
     * starts again and the player's name clip is fetched; on «Başla» with
     * nothing answered a home game is greeted before its first prompt,
     * which waits for the greeting.
     */
    startTurn(studentId: string | null, fresh: boolean): void {
        const named = this.cues.turn(studentId);

        if (named !== null) {
            this.options.names?.load(named);
        }

        const greeting = this.cues.greeting(fresh);

        if (greeting !== null) {
            this.say(clipsOf(greeting), 'queue');
        }
    }

    /**
     * The game ended under play (ADR 016 decision 7). The board froze under
     * the result a moment ago; the voice goes on: the clip being said ends,
     * the rest of its line and a prompt waiting give way, and the end is
     * said. When that clip is the name the end begins with (a praise that
     * began «Abbas!»), the end's phrase follows it, so the name is said once.
     */
    gameOver(solved: number): void {
        const end = this.cues.finished(solved);
        const current = this.speaking;
        let line = end === null ? null : clipsOf(end);

        this.over = true;
        this.drop(this.waiting);
        this.waiting = null;

        if (current !== null) {
            const [first, ...after] = line ?? [];
            const sameName =
                current.clip !== null &&
                'player' in current.clip &&
                first !== undefined &&
                'player' in first &&
                first.player === current.clip.player;

            current.rest = sameName ? after : [];

            if (sameName) {
                line = null;
            }

            if (current.state !== 'gap') {
                current.sound?.resume();
            } else if (current.rest.length > 0) {
                // The pause stood on the frozen scene clock: begin it again.
                this.after(NAME_GAP_MS, () => this.sayNext(current));
            } else {
                this.speaking = null;
                current.over?.();
            }
        }

        if (line !== null) {
            this.say(line, 'queue');
        }
    }

    /**
     * The device's silent mode («Səssiz»): the stage mutes what plays; the
     * voice starts nothing new meanwhile.
     */
    setMuted(muted: boolean): void {
        this.cues.mute(muted);
    }

    setPaused(paused: boolean): void {
        this.input.enabled = !paused;
        this.time.paused = paused;

        if (paused) {
            this.tweens.pauseAll();
        } else {
            this.tweens.resumeAll();
        }

        // A round's arrival and departure stop and go on with the motion
        // they belong to: a train rolling in stops chugging at once. The
        // short hit, miss and solved sounds play out (a game that finishes
        // pauses the board under the last round's celebration).
        for (const key of MOTION_SOUNDS) {
            for (const sound of this.sound.getAll(key)) {
                if (paused) {
                    sound.pause();
                } else {
                    sound.resume();
                }
            }
        }

        // The voice stops mid-word and goes on from there; the pause
        // before a name waits on the scene clock.
        const sound = this.speaking?.sound;

        if (sound) {
            if (paused) {
                sound.pause();
            } else {
                sound.resume();
            }
        }
    }

    /** The round the scene shows now, or null. */
    protected abstract currentRound(): Round | null;

    /** Things drawn once behind every round. */
    protected abstract scenery(): void;

    /**
     * The texture of the picture behind each of a round's items, by item id
     * (`figures.ts`): a word's own, or a figure; null for an item that stands
     * alone, and for every item of a pack without figures.
     */
    protected figuresOf(round: Round): Map<string, string | null> {
        const chosen = chooseFigures(
            round,
            this.options.figures,
            this.options.pictures,
        );

        return new Map(
            [...chosen].map(([itemId, url]) => [
                itemId,
                url === null ? null : figureKey(url),
            ]),
        );
    }

    /**
     * Loads the pictures (URLs) not loaded yet, rasterised as figures are
     * (`figureKey()`), then calls `ready` — at once when every one is there.
     * A picture that fails to load is left out: its item shows its text.
     */
    protected loadPictures(urls: (string | null)[], ready: () => void): void {
        const missing = [
            ...new Set(
                urls.filter(
                    (url): url is string =>
                        url !== null && !this.textures.exists(figureKey(url)),
                ),
            ),
        ];

        if (missing.length === 0) {
            ready();

            return;
        }

        for (const url of missing) {
            this.load.svg(figureKey(url), url, {
                width: FIGURE_RASTER,
                height: FIGURE_RASTER,
            });
        }

        this.load.once(Phaser.Loader.Events.COMPLETE, ready);
        this.load.start();
    }

    /** The pack palette, never empty. */
    protected get palette(): number[] {
        return this.options.palette.length > 0
            ? this.options.palette
            : [FALLBACK_COLOUR];
    }

    /**
     * Plays one of the pack's sounds, unless that sound is still playing
     * (or paused with the board): quick wrong taps (several children at a
     * `pop` board) never stack copies of the miss sound. A moment the pack
     * has no sound for plays nothing.
     */
    protected play(key: keyof BoardSounds): void {
        if (
            this.cache.audio.exists(key) &&
            !this.sound
                .getAll(key)
                .some((sound) => sound.isPlaying || sound.isPaused)
        ) {
            this.sound.play(key);
        }
    }

    /**
     * Plays a sound `delay` ms from now on the scene clock, so it starts
     * with the tweens it goes with and waits with them while the board is
     * paused.
     */
    protected playIn(delay: number, key: keyof BoardSounds): void {
        this.time.delayedCall(delay, () => this.play(key));
    }

    /**
     * Says the round's prompt `delay` ms from now on the scene clock (its
     * items have arrived by then), after the pack's `arrive` sound if that
     * is still playing; not when the round is gone by then, nor when
     * `VoiceCues` says no. The clip starts loading now.
     */
    protected sayPromptIn(
        delay: number,
        round: Round,
        progress: RoundProgress,
    ): void {
        const url = this.cues.arrived(round, this.isOpen(progress));

        if (url === null) {
            return;
        }

        this.loadVoice(url, () => undefined);
        this.time.delayedCall(delay, () => {
            const arriving = this.sound
                .getAll('arrive')
                .find((sound) => sound.isPlaying || sound.isPaused);
            const say = () => {
                if (this.currentRound()?.id === round.id) {
                    this.say([{ url }], 'queue');
                }
            };

            if (arriving === undefined) {
                say();
            } else {
                arriving.once(Phaser.Sound.Events.COMPLETE, say);
            }
        });
    }

    /**
     * Praise once the `solved` sound, played `delay` ms from now, is over,
     * with the player's name after it when it is due. The controller holds
     * the next round for the line: the scene tells it the line is due, and
     * then that it is over — said, or dropped or cut — once (`bridge.praise`).
     */
    protected sayPraiseAfter(delay: number, roundId: string): void {
        const line = this.cues.solved();

        if (line === null) {
            return;
        }

        const bridge = this.options.bridge;
        let told = false;
        const over = () => {
            if (!told) {
                told = true;
                bridge.praise({ roundId, state: 'over' });
            }
        };

        bridge.praise({ roundId, state: 'due' });
        this.time.delayedCall(
            delay + this.lengthOf('solved') + VOICE_GAP_MS,
            () => this.say(clipsOf(line), 'queue', over),
        );
    }

    /** The retry phrase after the `miss` sound, once a round, into silence. */
    protected sayRetry(roundId: string): void {
        const url = this.cues.missed(roundId);

        if (url !== null) {
            this.time.delayedCall(this.lengthOf('miss') + VOICE_GAP_MS, () =>
                this.say([{ url }], 'idle'),
            );
        }
    }

    /**
     * Whether a round shown is still to be played: one shown already
     * solved or skipped (a reload) does not arrive with a sound.
     */
    protected isOpen(progress: RoundProgress): boolean {
        return !progress.solved && !progress.skipped;
    }

    /** The length of one of the pack's sounds in ms; 0 when it has none. */
    private lengthOf(key: keyof BoardSounds): number {
        const audio: unknown = this.cache.audio.exists(key)
            ? this.cache.audio.get(key)
            : null;

        return typeof AudioBuffer !== 'undefined' &&
            audio instanceof AudioBuffer
            ? audio.duration * 1000
            : 0;
    }

    /**
     * The voice's one channel (`VoiceTurn`): a line at a time, in order. A
     * line queued takes the place of the one waiting; `over` is called once
     * the line is said, or dropped or cut.
     */
    private say(line: Clip[], turn: VoiceTurn, over: Over = null): void {
        if (turn === 'now') {
            this.hush();
        } else if (this.speaking !== null) {
            if (turn === 'queue') {
                this.drop(this.waiting);
                this.waiting = { clips: line, over };
            } else {
                over?.();
            }

            return;
        }

        const utterance: Utterance = {
            rest: [...line],
            clip: null,
            sound: null,
            state: 'loading',
            over,
        };

        this.speaking = utterance;
        this.sayNext(utterance);
    }

    /** Says the next clip of a line, or frees the channel for the line waiting. */
    private sayNext(utterance: Utterance): void {
        if (this.speaking !== utterance) {
            return;
        }

        const clip = utterance.rest.shift();

        if (clip === undefined) {
            this.speaking = null;
            utterance.over?.();
            this.sayWaiting();

            return;
        }

        utterance.state = 'loading';
        utterance.clip = clip;
        utterance.sound = null;
        this.loadClip(clip, utterance.rest, (key) => {
            if (this.speaking !== utterance || !this.sys.isActive()) {
                return;
            }

            // A name not fetched yet is left out and the phrase is said
            // alone; a phrase that cannot be said ends its line, so a name
            // is never said without its phrase.
            if (key === null) {
                if ('url' in clip) {
                    utterance.rest = [];
                }

                this.sayNext(utterance);

                return;
            }

            const sound = this.sound.add(key);

            utterance.sound = sound;
            utterance.state = 'playing';
            sound.once(Phaser.Sound.Events.COMPLETE, () => {
                sound.destroy();

                if (this.speaking !== utterance) {
                    return;
                }

                utterance.sound = null;

                if (utterance.rest.length === 0) {
                    this.sayNext(utterance);
                } else {
                    utterance.state = 'gap';
                    this.after(NAME_GAP_MS, () => this.sayNext(utterance));
                }
            });
            sound.play();
        });
    }

    private sayWaiting(): void {
        const next = this.waiting;

        this.waiting = null;

        if (next !== null) {
            this.say(next.clips, 'queue', next.over);
        }
    }

    /** A line that will not be said (any more). */
    private drop(line: { over: Over } | null): void {
        line?.over?.();
    }

    /** Stops the line being said and forgets the one waiting. */
    private hush(): void {
        const speaking = this.speaking;
        const waiting = this.waiting;
        const sound = speaking?.sound;

        if (sound) {
            sound.stop();
            sound.destroy();
        }

        this.speaking = null;
        this.waiting = null;
        this.drop(speaking);
        this.drop(waiting);
    }

    /**
     * Runs `callback` `ms` from now: on the scene clock while the game is
     * on, so it waits while the board is paused; once the game is over the
     * board stands frozen under the result, and the page's clock runs it.
     */
    private after(ms: number, callback: () => void): void {
        if (!this.over) {
            this.time.delayedCall(ms, callback);

            return;
        }

        setTimeout(() => {
            if (this.sys.isActive()) {
                callback();
            }
        }, ms);
    }

    /**
     * The cache key of a clip ready to say, or null: a content file loads
     * once; a name is said only when its clip has been fetched and, said
     * first, when the phrase after it (in `rest`) has loaded.
     */
    private loadClip(
        clip: Clip,
        rest: Clip[],
        ready: (key: string | null) => void,
    ): void {
        if ('url' in clip) {
            this.loadVoice(clip.url, (ok) =>
                ready(ok ? voiceKey(clip.url) : null),
            );

            return;
        }

        const key = nameKey(clip.player);

        if (!this.cache.audio.exists(key)) {
            const decoded = this.options.names?.get(clip.player) ?? null;

            if (decoded === null) {
                ready(null);

                return;
            }

            this.cache.audio.add(key, decoded);
        }

        // A name said first waits until its phrase is there to follow it.
        const phrase = rest.find((next) => 'url' in next);

        if (phrase === undefined || !('url' in phrase)) {
            ready(key);

            return;
        }

        this.loadVoice(phrase.url, (ok) => ready(ok ? key : null));
    }

    /**
     * Loads a clip once, then calls `done` with whether it loaded; a clip
     * that fails to load is never said.
     */
    private loadVoice(url: string, done: (ok: boolean) => void): void {
        const key = voiceKey(url);

        if (this.cache.audio.exists(key)) {
            done(true);

            return;
        }

        const waiting = this.loading.get(key);

        if (waiting !== undefined) {
            waiting.push(done);

            return;
        }

        const loaded = `${Phaser.Loader.Events.FILE_KEY_COMPLETE}audio-${key}`;
        const finish = (ok: boolean) => {
            const callbacks = this.loading.get(key) ?? [];

            this.load.off(loaded, onLoaded);
            this.load.off(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
            this.loading.delete(key);
            callbacks.forEach((callback) => callback(ok));
        };
        const onLoaded = () => finish(true);
        const onError = (file: Phaser.Loader.File) => {
            if (file.key === key) {
                finish(false);
            }
        };

        this.loading.set(key, [done]);
        this.load.on(loaded, onLoaded);
        this.load.on(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
        this.load.audio(key, url);
        this.load.start();
    }
}
