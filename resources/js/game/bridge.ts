/**
 * The one typed bridge between the board's logic and its canvas
 * (docs/architecture.md §4, "Board in the browser"). React and the board
 * controller (and, from A7, `live.ts` through the controller) never touch
 * Phaser; the scene never judges, stores or sends anything.
 *
 * In (controller → scene): `showRound` draws a round as it stands,
 * `applyHit` plays what the judge decided for one touch, `setPaused`
 * freezes the canvas. Out (scene → controller): `itemTouched`, a pointer
 * on an item. The controller judges the touch and calls `applyHit` in the
 * same call stack, so the effect starts in the frame of the touch, before
 * the server has answered.
 *
 * The scene boots asynchronously (Phaser is loaded on demand), so the
 * bridge keeps the last round and pause state and replays them when the
 * scene connects.
 */
import type { Round, RoundProgress } from './contract';

/** A pointer on an item of the round the scene shows. */
export type ItemTouch = { roundId: string; itemId: string };

/** What one touch came to, for the scene to play. */
export type HitEffect = {
    roundId: string;
    itemId: string;
    status: 'correct' | 'miss' | 'stale';
    /** This hit solved the round. */
    solved: boolean;
};

/** What a scene implements to be driven through the bridge. */
export type BridgeScene = {
    showRound: (round: Round, progress: RoundProgress) => void;
    applyHit: (effect: HitEffect) => void;
    setPaused: (paused: boolean) => void;
};

type Listener<T> = (value: T) => void;

export class BoardBridge {
    private scene: BridgeScene | null = null;
    private lastRound: { round: Round; progress: RoundProgress } | null = null;
    private paused = false;
    private readonly touchListeners = new Set<Listener<ItemTouch>>();

    // In: controller → scene.

    showRound(round: Round, progress: RoundProgress): void {
        this.lastRound = { round, progress };
        this.scene?.showRound(round, progress);
    }

    applyHit(effect: HitEffect): void {
        this.scene?.applyHit(effect);
    }

    setPaused(paused: boolean): void {
        this.paused = paused;
        this.scene?.setPaused(paused);
    }

    // Out: scene → controller.

    itemTouched(touch: ItemTouch): void {
        for (const listener of this.touchListeners) {
            listener(touch);
        }
    }

    onItemTouched(listener: Listener<ItemTouch>): () => void {
        this.touchListeners.add(listener);

        return () => {
            this.touchListeners.delete(listener);
        };
    }

    /** The scene attaches itself once it is ready; it gets the current round at once. */
    connect(scene: BridgeScene): () => void {
        this.scene = scene;

        if (this.lastRound !== null) {
            scene.showRound(this.lastRound.round, this.lastRound.progress);
        }

        scene.setPaused(this.paused);

        return () => {
            if (this.scene === scene) {
                this.scene = null;
            }
        };
    }
}
