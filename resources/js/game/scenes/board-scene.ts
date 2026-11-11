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
import type { ItemStyle, SlotStyle } from '../styles/types';
import type { BoardVoice } from '../voice';
import { VoiceCues } from '../voice';

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
 * How the voice waits for a free channel (§3 "Voice"): a prompt or praise
 * waits for the clip being said; the retry phrase is said only into
 * silence; 🔊 cuts in.
 */
type VoiceTurn = 'queue' | 'idle' | 'now';

/** The voice's texture-cache keys, apart from the pack's sound keys. */
function voiceKey(url: string): string {
    return `voice:${url}`;
}

/** The clip a miss or solved sound is followed by starts this long after it ends. */
const VOICE_GAP_MS = 80;

export abstract class BoardScene extends Phaser.Scene implements BridgeScene {
    private disconnect: (() => void) | null = null;
    private readonly cues: VoiceCues;
    /** The clip being said (or loading to be said), and the one waiting for it. */
    private speaking: Phaser.Sound.BaseSound | 'loading' | null = null;
    private waiting: string | null = null;
    private readonly loading = new Map<string, (() => void)[]>();

    constructor(
        key: string,
        protected readonly options: SceneOptions,
    ) {
        super({ key });
        this.cues = new VoiceCues(options.voice);
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
            this.say(url, 'now');
        }
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

        // The voice stops mid-word and goes on from there.
        if (this.speaking !== null && this.speaking !== 'loading') {
            if (paused) {
                this.speaking.pause();
            } else {
                this.speaking.resume();
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
                    this.say(url, 'queue');
                }
            };

            if (arriving === undefined) {
                say();
            } else {
                arriving.once(Phaser.Sound.Events.COMPLETE, say);
            }
        });
    }

    /** Praise once the `solved` sound, played `delay` ms from now, is over. */
    protected sayPraiseAfter(delay: number): void {
        const url = this.cues.solved();

        if (url !== null) {
            this.time.delayedCall(
                delay + this.lengthOf('solved') + VOICE_GAP_MS,
                () => this.say(url, 'queue'),
            );
        }
    }

    /** The retry phrase after the `miss` sound, once a round, into silence. */
    protected sayRetry(roundId: string): void {
        const url = this.cues.missed(roundId);

        if (url !== null) {
            this.time.delayedCall(this.lengthOf('miss') + VOICE_GAP_MS, () =>
                this.say(url, 'idle'),
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

    /** The voice's one channel (`VoiceTurn`). */
    private say(url: string, turn: VoiceTurn): void {
        if (turn === 'now') {
            this.hush();
        } else if (this.speaking !== null) {
            if (turn === 'queue') {
                this.waiting = url;
            }

            return;
        }

        this.speaking = 'loading';
        this.loadVoice(url, () => {
            if (this.speaking !== 'loading' || !this.sys.isActive()) {
                return;
            }

            const sound = this.sound.add(voiceKey(url));

            this.speaking = sound;
            sound.once(Phaser.Sound.Events.COMPLETE, () => {
                sound.destroy();

                if (this.speaking === sound) {
                    this.speaking = null;
                    this.sayWaiting();
                }
            });
            sound.play();
        });
    }

    private sayWaiting(): void {
        const next = this.waiting;

        this.waiting = null;

        if (next !== null) {
            this.say(next, 'queue');
        }
    }

    /** Stops the clip being said and forgets the one waiting. */
    private hush(): void {
        if (this.speaking !== null && this.speaking !== 'loading') {
            this.speaking.stop();
            this.speaking.destroy();
        }

        this.speaking = null;
        this.waiting = null;
    }

    /**
     * Loads a clip once, then calls `ready`; a clip that fails to load is
     * never said (and keeps the channel busy no longer).
     */
    private loadVoice(url: string, ready: () => void): void {
        const key = voiceKey(url);

        if (this.cache.audio.exists(key)) {
            ready();

            return;
        }

        const waiting = this.loading.get(key);

        if (waiting !== undefined) {
            waiting.push(ready);

            return;
        }

        const loaded = `${Phaser.Loader.Events.FILE_KEY_COMPLETE}audio-${key}`;
        const finish = (ok: boolean) => {
            const callbacks = this.loading.get(key) ?? [];

            this.load.off(loaded, onLoaded);
            this.load.off(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
            this.loading.delete(key);

            if (ok) {
                callbacks.forEach((callback) => callback());
            } else if (this.speaking === 'loading') {
                this.speaking = null;
                this.sayWaiting();
            }
        };
        const onLoaded = () => finish(true);
        const onError = (file: Phaser.Loader.File) => {
            if (file.key === key) {
                finish(false);
            }
        };

        this.loading.set(key, [ready]);
        this.load.on(loaded, onLoaded);
        this.load.on(Phaser.Loader.Events.FILE_LOAD_ERROR, onError);
        this.load.audio(key, url);
        this.load.start();
    }
}
