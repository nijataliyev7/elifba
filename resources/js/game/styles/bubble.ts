/**
 * The `bubble` item style (docs/architecture.md §2): a soap bubble tinted
 * with a pack colour, with a light rim, two highlights and its item's text
 * in dark letters. Bubbles grow in from below, drift and breathe while
 * they wait, jiggle on a miss and float up out of sight when their round
 * is over.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import { BoardItem, itemLabel, shade } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const MARGIN = 130;

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    const { width, colour } = look;
    const r = width / 2;
    const fill = shade(colour, 0.72);
    const bubble = scene.add.graphics();

    bubble.fillStyle(fill, 0.85);
    bubble.fillCircle(0, 0, r);
    bubble.fillStyle(colour, 0.22);
    bubble.fillCircle(r * 0.08, r * 0.1, r * 0.86);
    bubble.lineStyle(8, colour, 0.9);
    bubble.strokeCircle(0, 0, r);
    bubble.lineStyle(3, 0xffffff, 0.8);
    bubble.strokeCircle(0, 0, r - 7);

    // Highlights: an arc at the top left and a dot below it.
    bubble.lineStyle(r * 0.09, 0xffffff, 0.85);
    bubble.beginPath();
    bubble.arc(
        0,
        0,
        r * 0.74,
        Phaser.Math.DegToRad(200),
        Phaser.Math.DegToRad(250),
    );
    bubble.strokePath();
    bubble.fillStyle(0xffffff, 0.85);
    bubble.fillCircle(
        Math.cos(Phaser.Math.DegToRad(265)) * r * 0.74,
        Math.sin(Phaser.Math.DegToRad(265)) * r * 0.74,
        r * 0.05,
    );

    const label = itemLabel(scene, text, width * 0.42, fill, width * 0.74);

    return {
        root: scene.add.container(0, 0, [bubble, label]),
        body: bubble,
        hitArea: {
            hitArea: new Phaser.Geom.Circle(0, 0, r * 1.04),
            hitAreaCallback: (
                area: Phaser.Geom.Circle,
                px: number,
                py: number,
            ) => Phaser.Geom.Circle.Contains(area, px, py),
            useHandCursor: true,
        },
        width,
        height: width,
    };
}

export class Bubble extends BoardItem {
    constructor(
        scene: Phaser.Scene,
        item: RoundItem,
        options: ItemViewOptions,
    ) {
        const { x, y, width, colour } = options;

        super(
            scene,
            item.id,
            colour,
            draw(scene, item.text, { width, colour }),
            x,
            y,
        );
    }

    /** Grows from a small bubble low on the screen as it rises in. */
    enter(delay: number): void {
        const { scene, root } = this;

        root.y = scene.scale.height - this.drawing.height * 0.3;
        root.setScale(0.15);
        scene.tweens.add({
            targets: root,
            y: this.restY,
            scale: 1,
            delay,
            duration: 900,
            ease: 'Sine.easeOut',
            onComplete: () => this.drift(),
        });
    }

    /** A wrong bubble jiggles, squashing and stretching, and stays. */
    wobble(): void {
        if (this.gone) {
            return;
        }

        this.scene.tweens.killTweensOf(this.inner);
        this.inner.setScale(1).setAngle(0);
        this.scene.tweens.add({
            targets: this.inner,
            scaleX: [1.14, 0.9, 1.06, 0.97, 1],
            scaleY: [0.88, 1.1, 0.95, 1.03, 1],
            duration: 520,
            ease: 'Sine.easeInOut',
        });
    }

    /** The round is over: the bubble floats up and away. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.root,
            y: -this.drawing.height,
            x: this.root.x + Phaser.Math.Between(-120, 120),
            alpha: 0.3,
            delay,
            duration: 1300,
            ease: 'Sine.easeIn',
            onComplete: () => this.destroy(),
        });
    }

    private drift(): void {
        const period = Phaser.Math.Between(1800, 2600);

        this.idle([
            {
                targets: this.root,
                y: this.restY - 26,
                duration: period,
                ease: 'Sine.easeInOut',
            },
            {
                targets: this.root,
                x: this.restX + Phaser.Math.Between(14, 22),
                duration: period * 1.3,
                ease: 'Sine.easeInOut',
            },
            {
                targets: this.root,
                scale: 1.04,
                duration: period * 0.7,
                ease: 'Sine.easeInOut',
            },
        ]);
    }
}

export const bubble: ItemStyle = {
    stagger: 110,
    layout(count, band) {
        const slot = (1920 - MARGIN * 2) / Math.max(count, 1);
        // In a band the drift (26 px up) and the breathing stay inside it.
        const fit =
            band === undefined
                ? Infinity
                : (band.bottom - band.top - 30) / 1.04;
        const width = Math.min(310, slot * 0.8, fit);

        return {
            width,
            height: width,
            spots: Array.from({ length: count }, (_, index) => ({
                x: MARGIN + slot * (index + 0.5),
                y:
                    band === undefined
                        ? 1080 * 0.46 + (index % 2 === 0 ? 30 : -30)
                        : (band.top + band.bottom) / 2 + 13,
            })),
        };
    },
    draw,
    create: (scene, item, options) => new Bubble(scene, item, options),
};
