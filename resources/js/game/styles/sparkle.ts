/**
 * The `sparkle` effect (docs/architecture.md §2, §8): a correct item glows,
 * lifts and fades away whole while stars in its colour and white twinkle
 * over it and rise with it. Nothing breaks, scatters or falls: the
 * national symbols and the cities use it because a state symbol is never
 * burst.
 */
import Phaser from 'phaser';
import { shade } from './item';
import type { Effect } from './types';

const STAR = 'effect-sparkle-star';
const SIZE = 32;

/** A four-pointed star, drawn once per game: white, so the tint gives it colour. */
function ensureStar(scene: Phaser.Scene): void {
    if (scene.textures.exists(STAR)) {
        return;
    }

    const c = SIZE / 2;
    const thin = SIZE * 0.12;
    const shape = scene.make.graphics({}, false);

    shape.fillStyle(0xffffff, 1);
    const points = [
        [c, 0],
        [c + thin, c - thin],
        [SIZE, c],
        [c + thin, c + thin],
        [c, SIZE],
        [c - thin, c + thin],
        [0, c],
        [c - thin, c - thin],
    ].map(([x, y]) => new Phaser.Math.Vector2(x, y));

    shape.fillPoints(points, true);
    shape.fillCircle(c, c, thin * 1.2);
    shape.generateTexture(STAR, SIZE, SIZE);
    shape.destroy();
}

function twinkle(
    scene: Phaser.Scene,
    x: number,
    y: number,
    area: { width: number; height: number },
    colour: number,
    count: number,
): void {
    const stars = scene.add.particles(x, y, STAR, {
        emitZone: {
            type: 'random',
            source: new Phaser.Geom.Rectangle(
                -area.width * 0.6,
                -area.height * 0.6,
                area.width * 1.2,
                area.height * 1.2,
            ),
            quantity: count,
        },
        speed: { min: 10, max: 70 },
        angle: { min: 240, max: 300 },
        rotate: { start: 0, end: 90 },
        lifespan: { min: 700, max: 1200 },
        scale: { values: [0, 1.5, 1.1, 0], interpolation: 'catmull' },
        gravityY: -160,
        tint: [colour, 0xffffff, shade(colour, 0.5)],
        emitting: false,
    });

    stars.setDepth(10);
    stars.explode(count);
    scene.time.delayedCall(1400, () => stars.destroy());
}

export const sparkle: Effect = {
    play(scene, view) {
        ensureStar(scene);

        const { x, y } = view.centre();
        const area = view.size();
        const colour = view.colour;
        const glow = scene.add.ellipse(
            x,
            y,
            area.width * 1.1,
            area.height * 1.1,
            0xffffff,
            0.7,
        );

        glow.setDepth(-0.5);
        scene.tweens.add({
            targets: glow,
            scale: { from: 0.9, to: 1.35 },
            alpha: { from: 0.7, to: 0 },
            y: y - 120,
            duration: 900,
            ease: 'Sine.easeOut',
            onComplete: () => glow.destroy(),
        });

        view.lift();
        twinkle(scene, x, y, area, colour, 26);
        scene.time.delayedCall(260, () =>
            twinkle(scene, x, y - 90, area, colour, 16),
        );
    },
};
