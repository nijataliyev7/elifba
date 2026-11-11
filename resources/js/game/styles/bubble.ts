/**
 * The `bubble` item style (docs/architecture.md §2): a soap bubble tinted
 * with a pack colour, with a light rim, a highlight and its item's text in
 * dark letters. Bubbles grow in from below, drift and breathe while they
 * wait, jiggle on a miss and float up out of sight when their round is
 * over.
 *
 * It is drawn in ADR 014's Fluent manner, once per colour and size
 * (`bubbleTexture()`): no outline — a pale, see-through body lit from the
 * top left, its rim deepening into the colour and its shade at the bottom
 * right, and one soft highlight like a window's reflection.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import { css, highlight, mix, rgba, step, texture, tones } from './fluent';
import { BoardItem, itemLabel, shade } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const MARGIN = 130;
/** Room around the bubble in its texture ÷ the width. */
const PAD = 0.02;

/** The tint the bubble's middle shows, which the text is chosen for. */
function fillOf(colour: number): number {
    return shade(colour, 0.72);
}

/** The bubble without its text, painted once per colour and size. */
function bubbleTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
): { key: string; pad: number; w: number } {
    const w = step(width);
    const pad = Math.ceil(w * PAD);
    const key = `bubble-${css(colour)}-${w}`;

    texture(scene, key, w + pad * 2, w + pad * 2, (context) => {
        const r = w / 2;
        const c = pad + r;
        const fill = fillOf(colour);
        const t = tones(colour);
        const disc = () => {
            context.beginPath();
            context.arc(c, c, r, 0, Math.PI * 2);
        };

        // The body: pale and see-through, lit from the top left.
        const body = context.createRadialGradient(
            c - r * 0.4,
            c - r * 0.5,
            0,
            c - r * 0.4,
            c - r * 0.5,
            r * 1.7,
        );

        body.addColorStop(0, rgba(mix(fill, 0xffffff, 0.6), 0.9));
        body.addColorStop(0.45, rgba(fill, 0.86));
        body.addColorStop(1, rgba(mix(fill, colour, 0.35), 0.9));
        disc();
        context.fillStyle = body;
        context.fill();

        // The rim deepens into the colour, darkest at the bottom right.
        const rim = context.createRadialGradient(c, c, 0, c, c, r);

        rim.addColorStop(0.7, rgba(colour, 0));
        rim.addColorStop(0.9, rgba(colour, 0.5));
        rim.addColorStop(1, rgba(t.shade, 0.8));
        disc();
        context.fillStyle = rim;
        context.fill();

        const shadeSide = context.createRadialGradient(
            c + r,
            c + r,
            0,
            c + r,
            c + r,
            r * 1.5,
        );

        shadeSide.addColorStop(0, rgba(t.shade, 0.45));
        shadeSide.addColorStop(1, rgba(t.shade, 0));
        disc();
        context.fillStyle = shadeSide;
        context.fill();

        highlight(
            context,
            c - r * 0.45,
            c - r * 0.5,
            r * 0.3,
            r * 0.14,
            Phaser.Math.DegToRad(-42),
            0xffffff,
            0.95,
        );
    });

    return { key, pad, w };
}

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    const { width, colour } = look;
    const paint = bubbleTexture(scene, colour, width);
    const bubble = scene.add.image(0, 0, paint.key).setScale(width / paint.w);
    const label = itemLabel(
        scene,
        text,
        width * 0.42,
        fillOf(colour),
        width * 0.74,
    );

    return {
        root: scene.add.container(0, 0, [bubble, label]),
        body: bubble,
        // The image's own frame, in texture px: the touch takes the bubble.
        hitArea: {
            hitArea: new Phaser.Geom.Circle(
                paint.pad + paint.w / 2,
                paint.pad + paint.w / 2,
                (paint.w / 2) * 1.04,
            ),
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
