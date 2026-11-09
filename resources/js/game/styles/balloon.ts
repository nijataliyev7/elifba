/**
 * The `balloon` item style (docs/architecture.md §2, "Item styles and
 * effects"): a round balloon in a pack colour with its item's text, a knot
 * and a string. It rises in when a round starts, floats while it waits,
 * wobbles on a miss and drifts away when its round is over; the effect
 * (`burst`, or `sparkle`) does the rest.
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

const MARGIN = 120;

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    const { width, colour } = look;
    const height = Math.round(width * 1.18);
    const tail = height / 2;
    const string = scene.add.graphics();

    string.lineStyle(4, 0x6b7280, 0.9);
    string.beginPath();
    string.moveTo(0, tail + 14);
    string.lineTo(-14, tail + 70);
    string.lineTo(10, tail + 130);
    string.lineTo(-6, tail + 200);
    string.strokePath();

    const knot = scene.add.triangle(
        0,
        tail + 6,
        -16,
        16,
        16,
        16,
        0,
        0,
        shade(colour, -0.2),
    );
    const body = scene.add.ellipse(0, 0, width, height, colour);

    body.setStrokeStyle(6, shade(colour, -0.25), 1);

    const shine = scene.add.ellipse(
        -width * 0.22,
        -height * 0.24,
        width * 0.2,
        height * 0.3,
        0xffffff,
        0.35,
    );

    shine.setAngle(28);

    const label = itemLabel(scene, text, width * 0.5, colour, width * 0.8);

    return {
        root: scene.add.container(0, 0, [string, knot, body, shine, label]),
        body,
        // A shape's hit area starts at its top left corner.
        hitArea: {
            hitArea: new Phaser.Geom.Ellipse(
                width / 2,
                height / 2,
                width * 1.08,
                height * 1.08,
            ),
            hitAreaCallback: (
                area: Phaser.Geom.Ellipse,
                px: number,
                py: number,
            ) => Phaser.Geom.Ellipse.Contains(area, px, py),
            useHandCursor: true,
        },
        width,
        height,
    };
}

export class Balloon extends BoardItem {
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

    /** Rises in from below the screen, then floats. */
    enter(delay: number): void {
        const { scene, root } = this;

        root.y = scene.scale.height + this.drawing.height;
        scene.tweens.add({
            targets: root,
            y: this.restY,
            delay,
            duration: 750,
            ease: 'Back.easeOut',
            onComplete: () => this.float(),
        });
    }

    /** The round is over: the balloon lets go and drifts up out of sight. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.root,
            y: -this.drawing.height * 1.6,
            alpha: 0.4,
            delay,
            duration: 1100,
            ease: 'Sine.easeIn',
            onComplete: () => this.destroy(),
        });
    }

    private float(): void {
        const period = Phaser.Math.Between(1500, 2200);

        this.idle([
            {
                targets: this.root,
                y: this.restY - 22,
                duration: period,
                ease: 'Sine.easeInOut',
            },
            {
                targets: this.root,
                angle: { from: -3, to: 3 },
                duration: period * 1.4,
                ease: 'Sine.easeInOut',
            },
        ]);
    }
}

export const balloon: ItemStyle = {
    stagger: 110,
    layout(count, band) {
        const slot = (1920 - MARGIN * 2) / Math.max(count, 1);
        // In a band the floating (22 px up) stays inside it; the strings
        // may hang below.
        const fit =
            band === undefined
                ? Infinity
                : (band.bottom - band.top - 26) / 1.18;
        const width = Math.min(320, slot * 0.8, fit);

        return {
            width,
            height: Math.round(width * 1.18),
            spots: Array.from({ length: count }, (_, index) => ({
                x: MARGIN + slot * (index + 0.5),
                // Neighbours sit at slightly different heights, as balloons do.
                y:
                    band === undefined
                        ? 1080 * 0.42 + (index % 2 === 0 ? -40 : 50)
                        : (band.top + band.bottom) / 2 + 11,
            })),
        };
    },
    draw,
    create: (scene, item, options) => new Balloon(scene, item, options),
};
