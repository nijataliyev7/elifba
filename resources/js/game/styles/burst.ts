/**
 * The `burst` effect (docs/architecture.md §2): a correct item swells and
 * bursts into shards of its own colour with a ring of air, in the frame of
 * the touch. A state symbol is never burst (§8): its pack's effect is
 * `sparkle` (`styles/sparkle.ts`).
 */
import Phaser from 'phaser';
import type { Effect } from './types';

const SHARD = 'effect-burst-shard';

/** The shard texture, drawn once per game: white, so the tint gives it colour. */
function ensureShard(scene: Phaser.Scene): void {
    if (scene.textures.exists(SHARD)) {
        return;
    }

    const shape = scene.make.graphics({}, false);

    shape.fillStyle(0xffffff, 1);
    shape.fillTriangle(0, 18, 9, 0, 18, 18);
    shape.generateTexture(SHARD, 18, 18);
    shape.destroy();
}

function burstAt(
    scene: Phaser.Scene,
    x: number,
    y: number,
    colour: number,
): void {
    ensureShard(scene);

    const ring = scene.add.circle(x, y, 60);

    // Above a build round's slots, where the copies burst.
    ring.setStrokeStyle(12, colour, 0.9).setDepth(5);
    scene.tweens.add({
        targets: ring,
        scale: { from: 0.4, to: 2.6 },
        alpha: { from: 1, to: 0 },
        duration: 420,
        ease: 'Cubic.easeOut',
        onComplete: () => ring.destroy(),
    });

    const shards = scene.add.particles(x, y, SHARD, {
        speed: { min: 380, max: 980 },
        angle: { min: 0, max: 360 },
        rotate: { min: 0, max: 360 },
        lifespan: { min: 550, max: 950 },
        scale: { start: 1.6, end: 0.3 },
        alpha: { start: 1, end: 0 },
        gravityY: 1100,
        tint: [colour, colour, 0xffffff],
        emitting: false,
    });

    shards.setDepth(5);
    shards.explode(34);
    scene.time.delayedCall(1100, () => shards.destroy());
}

export const burst: Effect = {
    play(scene, view) {
        const { x, y } = view.centre();

        view.pop();
        burstAt(scene, x, y, view.colour);
    },
};
