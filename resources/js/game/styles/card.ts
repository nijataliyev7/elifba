/**
 * The `card` item style (docs/architecture.md §2): a playing card with a
 * light face in a pack colour, a coloured border and band, and its item's
 * text. Cards are dealt from above and turn face up, hover while they
 * wait, shake on a miss and slide down out of sight when their round is
 * over. The national symbols and cities use cards with `sparkle`, so a
 * card hit there lifts away whole (§8).
 *
 * It is drawn in ADR 014's Fluent manner, once per colour and size
 * (`cardTexture()`): no outline — the card is a surface in the colour, lit
 * from the top left with its shade at the bottom right, its border and
 * bands where the face does not cover it — a soft shadow under it in the
 * colour's deep shade, never grey, a face that pales to near white where
 * the light falls and lies a little below the band, and one soft highlight
 * on the top band.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import {
    css,
    highlight,
    paintPlate,
    rgba,
    step,
    texture,
    tones,
} from './fluent';
import { BoardItem, itemLabel, shade } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const MARGIN = 140;
/** height ÷ width. */
const ASPECT = 1.3;
/** The band at the top and bottom ÷ the height. */
const BAND = 0.12;
/** The border at the sides ÷ the width. */
const BORDER = 0.04;
/** Room around the card in its texture, for the shadow ÷ the width. */
const PAD = 0.1;

/** The face's colour, which the text is chosen for. */
function faceOf(colour: number): number {
    return shade(colour, 0.84);
}

/** The card without its text, painted once per colour and size. */
function cardTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
): { key: string; pad: number; w: number; h: number } {
    const w = step(width);
    const h = Math.round(w * ASPECT);
    const pad = Math.ceil(w * PAD);
    const key = `card-${css(colour)}-${w}`;

    texture(scene, key, w + pad * 2, h + pad * 2, (context) => {
        const radius = Math.round(w * 0.1);
        const band = h * BAND;
        const border = Math.max(4, w * BORDER);
        const face = faceOf(colour);
        // The card in its colour, the face a little below the band.
        paintPlate(
            context,
            { x: pad, y: pad, width: w, height: h },
            {
                colour,
                radius,
                inset: { side: border, top: band, bottom: band },
                face,
                faceRadius: Math.max(4, radius * 0.35),
                shadow: { blur: w * 0.07, drop: w * 0.04 },
            },
        );

        // Small diamonds on the bands, as a card's corners have.
        context.fillStyle = rgba(tones(face, 0.5).light, 0.92);

        for (const y of [pad + band / 2, pad + h - band / 2]) {
            const d = h * 0.03;
            const x = pad + w / 2;

            context.beginPath();
            context.moveTo(x - d, y);
            context.lineTo(x, y - d);
            context.lineTo(x + d, y);
            context.lineTo(x, y + d);
            context.closePath();
            context.fill();
        }

        highlight(
            context,
            pad + w * 0.24,
            pad + band * 0.42,
            w * 0.14,
            band * 0.22,
            0,
            colour,
            0.8,
        );
    });

    return { key, pad, w, h };
}

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    const { width, colour } = look;
    const height = Math.round(width * ASPECT);
    const paint = cardTexture(scene, colour, width);
    const card = scene.add.image(0, 0, paint.key).setScale(width / paint.w);
    const label = itemLabel(
        scene,
        text,
        width * 0.46,
        faceOf(colour),
        width * 0.82,
    );

    return {
        root: scene.add.container(0, 0, [card, label]),
        body: card,
        // The image's own frame, in texture px: the touch takes the card.
        hitArea: {
            hitArea: new Phaser.Geom.Rectangle(
                paint.pad,
                paint.pad,
                paint.w,
                paint.h,
            ),
            hitAreaCallback: (
                area: Phaser.Geom.Rectangle,
                px: number,
                py: number,
            ) => Phaser.Geom.Rectangle.Contains(area, px, py),
            useHandCursor: true,
        },
        width,
        height,
    };
}

export class Card extends BoardItem {
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

    /** Dealt from above the screen, turning face up as it lands. */
    enter(delay: number): void {
        const { scene, root, inner } = this;

        root.y = -this.drawing.height;
        root.setAngle(Phaser.Math.Between(-14, 14));
        inner.scaleX = 0.05;
        scene.tweens.add({
            targets: root,
            y: this.restY,
            angle: 0,
            delay,
            duration: 620,
            ease: 'Cubic.easeOut',
        });
        scene.tweens.add({
            targets: inner,
            scaleX: 1,
            delay: delay + 380,
            duration: 300,
            ease: 'Back.easeOut',
            onComplete: () => this.hover(),
        });
    }

    /** The round is over: the card slides down and out of sight. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.root,
            y: this.scene.scale.height + this.drawing.height,
            angle: Phaser.Math.Between(-10, 10),
            delay,
            duration: 800,
            ease: 'Cubic.easeIn',
            onComplete: () => this.destroy(),
        });
    }

    private hover(): void {
        this.idle([
            {
                targets: this.root,
                y: this.restY - 12,
                duration: Phaser.Math.Between(1700, 2300),
                ease: 'Sine.easeInOut',
            },
        ]);
    }
}

export const card: ItemStyle = {
    stagger: 110,
    layout(count, band) {
        const slot = (1920 - MARGIN * 2) / Math.max(count, 1);
        // In a band the shadow and the hover stay inside it.
        const fit =
            band === undefined
                ? Infinity
                : (band.bottom - band.top - 30) / ASPECT;
        const width = Math.min(300, slot * 0.78, fit);

        return {
            width,
            height: Math.round(width * ASPECT),
            spots: Array.from({ length: count }, (_, index) => ({
                x: MARGIN + slot * (index + 0.5),
                y:
                    band === undefined
                        ? 1080 * 0.48
                        : (band.top + band.bottom) / 2 + 3,
            })),
        };
    },
    draw,
    create: (scene, item, options) => new Card(scene, item, options),
};
