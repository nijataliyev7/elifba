/**
 * What every scene of the board shares (docs/architecture.md §4, "one
 * Phaser scene per mechanic"): the pack's sounds and backdrop, the
 * scenery drawn once, the link to the bridge, pausing. Each mechanic's
 * scene draws its rounds on top: `find.ts`, and the three build scenes on
 * `build.ts`. A scene never judges, stores or sends anything.
 *
 * Logical size 1920×1080; the stage scales it with `Scale.FIT`. The prompt
 * and everything else around the canvas belong to React.
 */
import Phaser from 'phaser';
import type { BoardBridge, BridgeScene, HitEffect } from '../bridge';
import type { Round, RoundProgress } from '../contract';
import type { Figure, Pictures } from '../figures';
import { chooseFigures, figureKey } from '../figures';
import { FIGURE_RASTER } from '../styles/figure';
import type { ItemStyle, SlotStyle } from '../styles/types';

export const BOARD_WIDTH = 1920;
export const BOARD_HEIGHT = 1080;

export type BoardSounds = {
    hit: string | null;
    miss: string | null;
    solved: string | null;
};

export type SceneOptions = {
    bridge: BoardBridge;
    /** The pack's item style, from `styles/registry.ts`. */
    style: ItemStyle;
    /** The pack's slot style; a build scene needs one. */
    slots: SlotStyle | null;
    /** The pack palette as numbers (`0xE85D4A`). */
    palette: number[];
    sounds: BoardSounds;
    backdrop: string | null;
    /** The pack's figures (`pack.style.figures`), for `animal` and `fruit` items; empty for the rest. */
    figures: Figure[];
    /** The pictures that show a content key (`pack.pictures`), for the same items. */
    pictures: Pictures;
    /** The scene is ready and connected to the bridge. */
    onReady: () => void;
};

const FALLBACK_COLOUR = 0x3b82c4;

export abstract class BoardScene extends Phaser.Scene implements BridgeScene {
    private disconnect: (() => void) | null = null;

    constructor(
        key: string,
        protected readonly options: SceneOptions,
    ) {
        super({ key });
    }

    preload(): void {
        for (const [key, url] of Object.entries(this.options.sounds)) {
            if (url !== null) {
                this.load.audio(key, url);
            }
        }

        if (this.options.backdrop !== null) {
            // Backdrops are 16:9 SVGs with only a viewBox (architecture §8).
            // Loaded as a plain image they have no size of their own and
            // WebGL drew them black; rasterised at the board's size they
            // are sharp too.
            this.load.svg('backdrop', this.options.backdrop, {
                width: BOARD_WIDTH,
                height: BOARD_HEIGHT,
            });
        }

        // Pictures behind items are SVGs too (OpenMoji or own art, 72×72):
        // rasterised at the largest size an item draws them, so the build
        // copies reuse them.
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
        if (this.textures.exists('backdrop')) {
            const backdrop = this.add.image(
                BOARD_WIDTH / 2,
                BOARD_HEIGHT,
                'backdrop',
            );
            const cover = Math.max(
                BOARD_WIDTH / backdrop.width,
                BOARD_HEIGHT / backdrop.height,
            );

            backdrop.setOrigin(0.5, 1).setScale(cover).setDepth(-2);
        }

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

    setPaused(paused: boolean): void {
        this.input.enabled = !paused;
        this.time.paused = paused;

        if (paused) {
            this.tweens.pauseAll();
        } else {
            this.tweens.resumeAll();
        }
    }

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

    /** The pack palette, never empty. */
    protected get palette(): number[] {
        return this.options.palette.length > 0
            ? this.options.palette
            : [FALLBACK_COLOUR];
    }

    protected play(key: keyof BoardSounds): void {
        if (this.cache.audio.exists(key)) {
            this.sound.play(key);
        }
    }
}
