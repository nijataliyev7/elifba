/**
 * The `balloon` item style (docs/architecture.md §2, "Item styles and
 * effects"): a round balloon in a pack colour with its item's text, a knot
 * and a string. It rises in when a round starts, floats while it waits,
 * wobbles on a miss and drifts away when its round is over; the effect
 * (`burst`, or `sparkle`) does the rest.
 *
 * It is drawn in ADR 014's Fluent manner, once per colour and size
 * (`balloonTexture()`): no outline, the body lit from the top left
 * (light → base → shade) with its shade at the bottom right and a violet
 * dusk at its rim, one soft highlight, a knot in the shade and a curling
 * string in the colour's shade fading out at its end.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import {
    css,
    ellipse,
    highlight,
    mix,
    rgba,
    step,
    surface,
    texture,
    tones,
} from './fluent';
import { BoardItem, itemLabel } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const MARGIN = 120;
/** height ÷ width of the body. */
const ASPECT = 1.18;
/** How far the string hangs below the body ÷ the width (200 px at 320). */
const STRING = 0.64;
/** Room around the drawing in its texture ÷ the width. */
const PAD = 0.03;
/** Fluent's violet, which a round body darkens to at its rim. */
const DUSK = 0x8a4bb7;

type Paint = {
    key: string;
    /** The texture's px per board px. */
    scale: number;
    /** Where the body's centre is in the texture. */
    cx: number;
    cy: number;
    w: number;
    h: number;
};

/** The body, knot and string, painted once per colour and size. */
function balloonTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
): Paint {
    const w = step(width);
    const h = Math.round(w * ASPECT);
    const pad = Math.ceil(w * PAD);
    const hang = Math.round(w * STRING);
    const cx = pad + w / 2;
    const cy = pad + h / 2;
    const key = `balloon-${css(colour)}-${w}`;
    const t = tones(colour);

    texture(scene, key, w + pad * 2, h + hang + pad * 2, (context) => {
        const tail = cy + h / 2;
        const line = Math.max(3, w * 0.013);

        // The string curls down from the knot and fades out at its end.
        const string = context.createLinearGradient(0, tail, 0, tail + hang);

        string.addColorStop(0, css(t.shade));
        string.addColorStop(0.7, rgba(mix(t.shade, t.base, 0.4), 0.85));
        string.addColorStop(1, rgba(t.base, 0));
        context.save();
        context.strokeStyle = string;
        context.lineWidth = line;
        context.lineCap = 'round';
        context.beginPath();
        context.moveTo(cx, tail + w * 0.03);
        context.bezierCurveTo(
            cx - w * 0.09,
            tail + hang * 0.25,
            cx + w * 0.08,
            tail + hang * 0.5,
            cx + w * 0.01,
            tail + hang * 0.7,
        );
        context.bezierCurveTo(
            cx - w * 0.04,
            tail + hang * 0.82,
            cx - w * 0.05,
            tail + hang * 0.92,
            cx - w * 0.02,
            tail + hang - line,
        );
        context.stroke();
        context.restore();

        // The knot: a little tie under the body, in its shade.
        const knot = { width: w * 0.11, height: w * 0.065 };
        const knotShape = () => {
            const top = tail - knot.height * 0.35;

            context.beginPath();
            context.moveTo(cx - knot.width * 0.18, top);
            context.lineTo(cx + knot.width * 0.18, top);
            context.quadraticCurveTo(
                cx + knot.width * 0.5,
                top + knot.height,
                cx + knot.width * 0.32,
                top + knot.height,
            );
            context.lineTo(cx - knot.width * 0.32, top + knot.height);
            context.quadraticCurveTo(
                cx - knot.width * 0.5,
                top + knot.height,
                cx - knot.width * 0.18,
                top,
            );
            context.closePath();
        };

        surface(
            context,
            knotShape,
            {
                x: cx - knot.width / 2,
                y: tail - knot.height * 0.35,
                width: knot.width,
                height: knot.height,
            },
            t.shade,
            { depth: 0.6 },
        );

        // The body: lit from the top left, darker at the bottom right.
        const body = () => ellipse(context, cx, cy, w / 2, h / 2);

        surface(
            context,
            body,
            { x: cx - w / 2, y: cy - h / 2, width: w, height: h },
            colour,
        );

        // A violet dusk at the rim, as Fluent's balloon has.
        const rim = context.createRadialGradient(cx, cy, 0, cx, cy, h / 2);

        rim.addColorStop(0.72, rgba(DUSK, 0));
        rim.addColorStop(1, rgba(mix(t.shade, DUSK, 0.3), 0.4));
        context.save();
        context.translate(cx, cy);
        context.scale(w / h, 1);
        context.translate(-cx, -cy);
        context.fillStyle = rim;
        context.beginPath();
        context.arc(cx, cy, h / 2, 0, Math.PI * 2);
        context.fill();
        context.restore();

        highlight(
            context,
            cx - w * 0.21,
            cy - h * 0.25,
            w * 0.1,
            h * 0.16,
            Phaser.Math.DegToRad(30),
            colour,
            0.85,
        );
    });

    return { key, scale: width / w, cx, cy, w, h };
}

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    const { width, colour } = look;
    const height = Math.round(width * ASPECT);
    const paint = balloonTexture(scene, colour, width);
    const body = scene.add.image(0, 0, paint.key);

    body.setOrigin(paint.cx / body.width, paint.cy / body.height).setScale(
        paint.scale,
    );

    const label = itemLabel(scene, text, width * 0.5, colour, width * 0.8);

    return {
        root: scene.add.container(0, 0, [body, label]),
        body,
        // The image's own frame, in texture px: the touch takes the body.
        hitArea: {
            hitArea: new Phaser.Geom.Ellipse(
                paint.cx,
                paint.cy,
                paint.w * 1.08,
                paint.h * 1.08,
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
                : (band.bottom - band.top - 26) / ASPECT;
        const width = Math.min(320, slot * 0.8, fit);

        return {
            width,
            height: Math.round(width * ASPECT),
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
