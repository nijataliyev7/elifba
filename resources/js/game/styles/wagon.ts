/**
 * The `wagon` item style (docs/architecture.md §2): a goods wagon in a pack
 * colour with its item's text on a light board, on two spoked wheels. The
 * wagons of a round stand in a row on one track (the style's scenery);
 * they roll in from the left with turning wheels, chug while they wait,
 * rattle on a miss and roll away to the right when their round is over.
 *
 * It is drawn in ADR 014's Fluent manner, once per colour and size
 * (`wagonTexture()`): no outline — the body lit from the top left with its
 * shade at the bottom right, the roof and the frame under the body in the
 * colour's shade, the board set into the body with the shade of its edge
 * falling on it, one soft highlight. The wheels (`wheel()`), the couplers
 * and the track (`drawRails()`) are ADR 014's materials: tyres in its
 * violet black, discs and rails in metal, sleepers in wood — never grey.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import {
    css,
    highlight,
    METAL,
    rgba,
    roundedRect,
    shadow,
    step,
    surface,
    texture,
    tones,
    TYRE,
    WOOD,
} from './fluent';
import type { Material } from './fluent';
import { BoardItem, itemLabel, shade } from './item';
import type {
    Band,
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const MARGIN = 110;
/** The top of the rails on the 1920×1080 board (find). */
const RAIL_Y = 800;
/** How far the roof rises above a wagon's body. */
const ROOF = 18;
/** Room around a wagon in its texture. */
const PAD = 4;

/** The top of the rails under a band: the sleepers end at its bottom. */
function railIn(band: Band | undefined): number {
    return band === undefined ? RAIL_Y : band.bottom - 36;
}

/** A vertical gradient through a material, light at the top, for small flat parts. */
function lengthwise(
    context: CanvasRenderingContext2D,
    top: number,
    bottom: number,
    material: Material,
): CanvasGradient {
    const fill = context.createLinearGradient(0, top, 0, bottom);

    fill.addColorStop(0, css(material.light));
    fill.addColorStop(0.45, css(material.base));
    fill.addColorStop(1, css(material.shade));

    return fill;
}

/** Rails and sleepers across the board, their top at `y`: painted once. */
export function drawRails(scene: Phaser.Scene, y: number): void {
    const key = texture(scene, 'rails-1920', 1920, 40, (context) => {
        for (let x = 10; x < 1920; x += 64) {
            roundedRect(context, x, 6, 34, 30, 4);
            context.fillStyle = lengthwise(context, 6, 36, WOOD);
            context.fill();
        }

        // The rail, its soft shadow on the sleepers.
        const rail = () => {
            context.beginPath();
            context.rect(0, 0, 1920, 12);
        };

        shadow(context, rail, WOOD.shade, 6, 3, 0.35);
        rail();
        context.fillStyle = lengthwise(context, 0, 12, METAL);
        context.fill();
        context.fillStyle = rgba(METAL.light, 0.9);
        context.fillRect(0, 1, 1920, 2);
    });

    scene.add.image(0, y, key).setOrigin(0, 0).setDepth(-1);
}

type WagonDrawing = ItemDrawing & {
    wheels: Phaser.GameObjects.Image[];
    radius: number;
};

/** Height of the body and radius of the wheels for a wagon `width` wide. */
export function proportions(width: number): { height: number; radius: number } {
    return { height: Math.round(width * 0.62), radius: width * 0.12 };
}

/** A spoked wheel's texture, `radius` px: tyre, metal disc, spokes and hub, shaded round its middle so it may turn. */
function wheelTexture(scene: Phaser.Scene, radius: number): string {
    const r = Math.max(4, Math.ceil(radius));
    const side = r * 2 + 2;
    const c = side / 2;

    return texture(scene, `wheel-${r}`, side, side, (context) => {
        const disc = (rr: number) => {
            context.beginPath();
            context.arc(c, c, rr, 0, Math.PI * 2);
        };
        const tyre = context.createRadialGradient(c, c, 0, c, c, r);

        tyre.addColorStop(0.7, css(TYRE.light));
        tyre.addColorStop(0.84, css(TYRE.base));
        tyre.addColorStop(1, css(TYRE.shade));
        disc(r);
        context.fillStyle = tyre;
        context.fill();

        const metal = context.createRadialGradient(c, c, 0, c, c, r * 0.72);

        metal.addColorStop(0, css(METAL.light));
        metal.addColorStop(0.7, css(METAL.base));
        metal.addColorStop(1, css(METAL.shade));
        disc(r * 0.72);
        context.fillStyle = metal;
        context.fill();

        context.strokeStyle = css(TYRE.base);
        context.lineWidth = r * 0.14;
        context.lineCap = 'round';

        for (let spoke = 0; spoke < 3; spoke++) {
            const angle = (spoke * Math.PI) / 3;
            const dx = Math.cos(angle) * r * 0.66;
            const dy = Math.sin(angle) * r * 0.66;

            context.beginPath();
            context.moveTo(c - dx, c - dy);
            context.lineTo(c + dx, c + dy);
            context.stroke();
        }

        const hub = context.createRadialGradient(c, c, 0, c, c, r * 0.22);

        hub.addColorStop(0, css(TYRE.light));
        hub.addColorStop(1, css(TYRE.shade));
        disc(r * 0.22);
        context.fillStyle = hub;
        context.fill();
    });
}

/** A spoked wheel centred on (x, y); rotate it to roll. */
export function wheel(
    scene: Phaser.Scene,
    x: number,
    y: number,
    radius: number,
): Phaser.GameObjects.Image {
    const image = scene.add.image(x, y, wheelTexture(scene, radius));

    return image.setScale((radius * 2) / (image.width - 2));
}

type WagonPaint = {
    key: string;
    /** Where the body's centre is in the texture. */
    cx: number;
    cy: number;
    w: number;
    h: number;
};

/** The wagon without its wheels and text, painted once per colour and size. */
function wagonTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
): WagonPaint {
    const w = step(width);
    const { height: h } = proportions(w);
    const tw = Math.ceil(w * 1.14) + PAD * 2;
    const th = h + ROOF + PAD * 2;
    const cx = tw / 2;
    const cy = PAD + ROOF + h / 2;
    const key = `wagon-${css(colour)}-${w}`;
    const t = tones(colour);

    texture(scene, key, tw, th, (context) => {
        const left = cx - w / 2;
        const top = cy - h / 2;

        // Couplers, behind the body.
        context.fillStyle = lengthwise(
            context,
            cy + h * 0.18,
            cy + h * 0.28,
            TYRE,
        );
        context.fillRect(left - w * 0.07, cy + h * 0.18, w * 1.14, h * 0.1);

        // Roof, in the colour's shade.
        const roof = { x: left - 10, y: top - ROOF, width: w + 20, height: 30 };

        surface(
            context,
            () => roundedRect(context, roof.x, roof.y, roof.width, 30, 12),
            roof,
            t.shade,
            { depth: 0.7 },
        );

        // The body, lit from the top left.
        const body = () => roundedRect(context, left, top, w, h, 14);

        surface(
            context,
            body,
            { x: left, y: top, width: w, height: h },
            colour,
        );

        // The frame under the body, in its shade.
        context.save();
        body();
        context.clip();
        context.fillStyle = lengthwise(context, cy + h / 2 - 18, cy + h / 2, {
            light: t.shade,
            base: t.shade,
            shade: t.deep,
        });
        context.fillRect(left, cy + h / 2 - 18, w, 18);
        context.restore();

        highlight(
            context,
            left + w * 0.2,
            top + h * 0.06,
            w * 0.13,
            h * 0.035,
            0,
            colour,
            0.8,
        );

        // The text's board, set into the body.
        const board = {
            x: cx - (w * 0.8) / 2,
            y: top + h * 0.12,
            width: w * 0.8,
            height: h * 0.62,
        };
        const boardShape = () =>
            roundedRect(
                context,
                board.x,
                board.y,
                board.width,
                board.height,
                10,
            );

        surface(context, boardShape, board, shade(colour, 0.86), {
            depth: 0.3,
            shade: 0.25,
        });

        const edge = context.createLinearGradient(
            0,
            board.y,
            0,
            board.y + board.height * 0.14,
        );

        edge.addColorStop(0, rgba(t.deep, 0.22));
        edge.addColorStop(1, rgba(t.deep, 0));
        boardShape();
        context.fillStyle = edge;
        context.fill();
    });

    return { key, cx, cy, w, h };
}

function drawWagon(
    scene: Phaser.Scene,
    text: string,
    look: ItemLook,
): WagonDrawing {
    const { width, colour } = look;
    const { height, radius } = proportions(width);
    const top = -height / 2;
    const paint = wagonTexture(scene, colour, width);
    const scale = width / paint.w;
    const body = scene.add.image(0, 0, paint.key);

    body.setOrigin(paint.cx / body.width, paint.cy / body.height).setScale(
        scale,
    );

    const boardHeight = height * 0.62;
    const label = itemLabel(
        scene,
        text,
        height * 0.44,
        shade(colour, 0.86),
        width * 0.8 * 0.9,
    );

    label.setY(top + height * 0.12 + boardHeight / 2);

    const wheels = [-1, 1].map((side) =>
        wheel(scene, side * width * 0.3, height / 2, radius),
    );

    return {
        root: scene.add.container(0, 0, [body, ...wheels, label]),
        body,
        // The image's own frame, in texture px: body, roof and wheels.
        hitArea: {
            hitArea: new Phaser.Geom.Rectangle(
                paint.cx - paint.w / 2,
                PAD,
                paint.w,
                paint.h + ROOF + radius / scale,
            ),
            hitAreaCallback: (
                area: Phaser.Geom.Rectangle,
                px: number,
                py: number,
            ) => Phaser.Geom.Rectangle.Contains(area, px, py),
            useHandCursor: true,
        },
        width,
        height: height + radius,
        wheels,
        radius,
    };
}

export class Wagon extends BoardItem {
    private readonly wheels: Phaser.GameObjects.Image[];
    private readonly radius: number;

    constructor(
        scene: Phaser.Scene,
        item: RoundItem,
        options: ItemViewOptions,
    ) {
        const { x, y, width, colour } = options;
        const drawing = drawWagon(scene, item.text, { width, colour });

        super(scene, item.id, colour, drawing, x, y);
        this.wheels = drawing.wheels;
        this.radius = drawing.radius;
    }

    /**
     * Rolls in from the left of the screen, wheels turning. Every wagon
     * starts one board width to the left of its place, so a round's wagons
     * keep their spacing and arrive coupled, as one train.
     */
    enter(delay: number): void {
        this.root.x = this.restX - this.scene.scale.width;
        this.roll(this.restX, delay, 1300, 'Cubic.easeOut', () => this.chug());
    }

    /** A wrong wagon rattles on its wheels and stays. */
    wobble(): void {
        if (this.gone) {
            return;
        }

        this.scene.tweens.killTweensOf(this.inner);
        this.inner.setPosition(0, 0).setAngle(0);
        this.scene.tweens.add({
            targets: this.inner,
            x: [-16, 13, -9, 5, 0],
            angle: [-2, 2, -1, 0],
            duration: 460,
            ease: 'Sine.easeInOut',
        });
    }

    /** The round is over: the train rolls away to the right as one. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        this.roll(
            this.root.x + this.scene.scale.width,
            delay,
            1100,
            'Cubic.easeIn',
            () => this.destroy(),
        );
    }

    private roll(
        to: number,
        delay: number,
        duration: number,
        ease: string,
        done: () => void,
    ): void {
        const from = this.root.x;

        this.scene.tweens.add({
            targets: this.root,
            x: to,
            delay,
            duration,
            ease,
            onUpdate: () => {
                const turned = (this.root.x - from) / this.radius;

                for (const wheel of this.wheels) {
                    wheel.setRotation(turned);
                }
            },
            onComplete: done,
        });
    }

    private chug(): void {
        this.idle([
            {
                targets: this.root,
                y: this.restY - 5,
                duration: Phaser.Math.Between(380, 460),
                ease: 'Sine.easeInOut',
            },
        ]);
    }
}

export const wagon: ItemStyle = {
    // A train arrives and leaves as one: its wagons never pass each other.
    stagger: 0,
    layout(count, band) {
        const slot = (1920 - MARGIN * 2) / Math.max(count, 1);
        const rail = railIn(band);
        // In a band the roof and the chugging stay inside it.
        const fit =
            band === undefined ? Infinity : (rail - band.top - ROOF - 6) / 0.74;
        const width = Math.min(340, slot * 0.82, fit);
        const { height, radius } = proportions(width);

        return {
            width,
            height: height + radius,
            spots: Array.from({ length: count }, (_, index) => ({
                x: MARGIN + slot * (index + 0.5),
                // The wheels stand on the rails.
                y: rail - height / 2 - radius,
            })),
        };
    },
    scenery(scene, band) {
        drawRails(scene, railIn(band));
    },
    draw: (scene, text, look) => drawWagon(scene, text, look),
    create: (scene, item, options) => new Wagon(scene, item, options),
};
