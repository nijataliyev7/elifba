/**
 * The `wagon` slot style (docs/architecture.md §2): a train on its own
 * track — a locomotive with empty wagons coupled behind it, one per piece
 * of the answer, and a short end car carrying the fixed `.`, `?` or `!`.
 * The locomotive leads on the left, so the wagons read left to right from
 * it. The train rolls in from the right, puffs and chugs while it waits;
 * the next empty wagon glows; a copy of each correct tile lands in its
 * wagon (the scene keeps it there, so it rides along). Once the round is
 * solved the wagons carry one long sign with the unit whole, and the train
 * pulls away to the left.
 *
 * Drawn in ADR 014's Fluent manner, each part painted once per colour and
 * size, never with an outline: the locomotive's boiler and cab lit from
 * the top left in the pack colour, its roof, bands and dome in the
 * colour's shade, a glass window, a brass lamp with a soft glow, chimney,
 * chassis and cowcatcher in the guide's violet black; an empty wagon is a
 * pale well, a filled one a lit surface in its colour; the next wagon to
 * fill has a soft glow behind it; the steam is soft cloud, and the solved
 * unit's sign is a white face set into a plate in the pack colour.
 */
import Phaser from 'phaser';
import { textOn } from '../palette';
import {
    BRASS,
    CLOUD,
    css,
    glowTexture,
    highlight,
    METAL,
    mix,
    paintPlate,
    paintRecess,
    rgba,
    roundedRect,
    SKY,
    step,
    surface,
    texture,
    tones,
    TYRE,
} from './fluent';
import type { Material } from './fluent';
import { hex, shade } from './item';
import type { SlotRowOptions, SlotSpot, SlotStyle, SlotView } from './types';
import { drawRails, proportions, wheel } from './wagon';

const MARGIN = 80;
/** Space between two cars, where the couplers are. */
const GAP = 0.14;
const LOCOMOTIVE = 1.2;
const END_CAR = 0.5;
const ROOF = 18;
const PAPER = 0xffffff;
/** Room round a painted part in its texture. */
const PAD = 12;
/** How far the next wagon's glow spreads. */
const SPREAD = 18;

/** A vertical gradient through a material, light at the top. */
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

/** An image of a texture painted `pad` px larger all round, shown with its part `width` px wide. */
function sized(
    image: Phaser.GameObjects.Image,
    width: number,
    painted: number,
): Phaser.GameObjects.Image {
    return image.setScale(width / painted);
}

/**
 * A wagon's shell, `w` px wide, painted once per colour and size: empty, a
 * pale well; filled, a lit surface in the colour. Both stand on the frame
 * under the body, in the guide's violet black.
 */
function shellTexture(
    scene: Phaser.Scene,
    colour: number,
    w: number,
    filled: boolean,
): string {
    const { height: b } = proportions(w);
    const key = `train-shell-${filled ? 'filled' : 'empty'}-${css(colour)}-${w}`;

    return texture(scene, key, w + PAD * 2, b + PAD * 2, (context) => {
        const box = { x: PAD, y: PAD, width: w, height: b };
        const body = () => roundedRect(context, PAD, PAD, w, b, 14);

        if (filled) {
            surface(context, body, box, colour);
            highlight(
                context,
                PAD + w * 0.22,
                PAD + b * 0.1,
                w * 0.14,
                b * 0.04,
                0,
                colour,
                0.8,
            );
        } else {
            paintRecess(context, box, colour, 14);
        }

        // The frame under the body.
        const t = tones(colour);

        context.save();
        body();
        context.clip();
        context.fillStyle = lengthwise(
            context,
            PAD + b - 18,
            PAD + b,
            filled ? { light: t.shade, base: t.shade, shade: t.deep } : TYRE,
        );
        context.fillRect(PAD, PAD + b - 18, w, 18);
        context.restore();
    });
}

/** The end car without its mark, `w` px wide (a wagon's), painted once per colour and size. */
function endCarTexture(scene: Phaser.Scene, colour: number, w: number): string {
    const { height: b } = proportions(w);
    const car = w * END_CAR;
    const deck = b * 0.55;

    return texture(
        scene,
        `train-end-${css(colour)}-${w}`,
        car + PAD * 2,
        deck + PAD * 2,
        (context) => {
            context.fillStyle = lengthwise(
                context,
                PAD + deck - 18,
                PAD + deck,
                TYRE,
            );
            context.fillRect(PAD, PAD + deck - 18, car, 18);

            const box = { x: PAD, y: PAD, width: car, height: deck - 10 };

            surface(
                context,
                () => roundedRect(context, PAD, PAD, car, deck - 10, 10),
                box,
                shade(colour, 0.75),
                { depth: 0.6 },
            );
        },
    );
}

/** Where the locomotive's parts are, from its left end (`x0`) and the middle of a wagon's body. */
function locomotiveParts(w: number) {
    const { height: b, radius: r } = proportions(w);
    const length = w * LOCOMOTIVE;
    const boilerTop = -b * 0.28;
    const boilerHeight = b * 0.62;
    const cabLeft = length * 0.6;
    const cabTop = -b / 2 - w * 0.3;

    return {
        b,
        r,
        length,
        rail: b / 2 + r,
        boilerTop,
        boilerHeight,
        cabLeft,
        cabTop,
        chimney: length * 0.16,
        // The texture's room to the left of x0 and above the body's middle.
        left: length * 0.02 + PAD,
        top: -cabTop + 16 + PAD,
    };
}

/**
 * The locomotive without its wheels and rod, `w` px a wagon's width,
 * painted once per colour and size. Facing left: cowcatcher, lamp,
 * chimney, boiler with its bands and dome, cab with its roof and window.
 */
function locomotiveTexture(
    scene: Phaser.Scene,
    colour: number,
    w: number,
): string {
    const parts = locomotiveParts(w);
    const { b, length, rail, boilerTop, boilerHeight, cabLeft, cabTop } = parts;
    const t = tones(colour);

    return texture(
        scene,
        `train-locomotive-${css(colour)}-${w}`,
        parts.left + length * 0.98 + 12 + PAD,
        parts.top + rail + PAD,
        (context) => {
            context.translate(parts.left, parts.top);

            // Chassis and cowcatcher.
            context.fillStyle = lengthwise(context, b / 2 - 20, b / 2, TYRE);
            context.fillRect(length * 0.06, b / 2 - 20, length * 0.94, 20);
            context.beginPath();
            context.moveTo(-length * 0.02, rail - 4);
            context.lineTo(length * 0.1, rail - 4);
            context.lineTo(length * 0.1, b / 2 - 22);
            context.closePath();
            context.fillStyle = lengthwise(context, b / 2 - 22, rail - 4, TYRE);
            context.fill();

            // Chimney with its crown.
            const chimney = parts.chimney;

            surface(
                context,
                () => {
                    context.beginPath();
                    context.rect(
                        chimney,
                        boilerTop - w * 0.22,
                        w * 0.12,
                        w * 0.24,
                    );
                },
                {
                    x: chimney,
                    y: boilerTop - w * 0.22,
                    width: w * 0.12,
                    height: w * 0.24,
                },
                TYRE,
            );

            const crown = {
                x: chimney - w * 0.03,
                y: boilerTop - w * 0.27,
                width: w * 0.18,
                height: w * 0.07,
            };

            surface(
                context,
                () =>
                    roundedRect(
                        context,
                        crown.x,
                        crown.y,
                        crown.width,
                        crown.height,
                        6,
                    ),
                crown,
                TYRE,
            );

            // Boiler, its bands and the dome.
            const boiler = {
                x: length * 0.08,
                y: boilerTop,
                width: length * 0.56,
                height: boilerHeight,
            };
            const boilerShape = () =>
                roundedRect(
                    context,
                    boiler.x,
                    boiler.y,
                    boiler.width,
                    boiler.height,
                    boilerHeight * 0.3,
                );

            surface(context, boilerShape, boiler, colour);
            context.save();
            boilerShape();
            context.clip();

            for (const at of [0.22, 0.4]) {
                context.fillStyle = lengthwise(
                    context,
                    boilerTop,
                    boilerTop + boilerHeight,
                    {
                        light: mix(t.shade, t.base, 0.4),
                        base: t.shade,
                        shade: t.deep,
                    },
                );
                context.fillRect(length * at, boilerTop, 10, boilerHeight);
            }

            context.restore();
            highlight(
                context,
                length * 0.2,
                boilerTop + boilerHeight * 0.18,
                length * 0.12,
                boilerHeight * 0.07,
                0,
                colour,
                0.8,
            );

            const dome = {
                x: length * 0.44 - w * 0.08,
                y: boilerTop - w * 0.07,
                width: w * 0.16,
                height: w * 0.14,
            };

            surface(
                context,
                () => {
                    context.beginPath();
                    context.ellipse(
                        length * 0.44,
                        boilerTop,
                        w * 0.08,
                        w * 0.07,
                        0,
                        0,
                        Math.PI * 2,
                    );
                },
                dome,
                t.shade,
                { depth: 0.7 },
            );

            // Cab, its window and roof.
            const cab = {
                x: cabLeft,
                y: cabTop,
                width: length * 0.38,
                height: b / 2 - cabTop - 10,
            };

            surface(
                context,
                () =>
                    roundedRect(
                        context,
                        cab.x,
                        cab.y,
                        cab.width,
                        cab.height,
                        10,
                    ),
                cab,
                colour,
            );

            const window = {
                x: cabLeft + length * 0.07,
                y: cabTop + 26,
                width: length * 0.24,
                height: b * 0.36,
            };

            surface(
                context,
                () =>
                    roundedRect(
                        context,
                        window.x,
                        window.y,
                        window.width,
                        window.height,
                        8,
                    ),
                window,
                SKY,
            );
            highlight(
                context,
                window.x + window.width * 0.3,
                window.y + window.height * 0.28,
                window.width * 0.2,
                window.height * 0.12,
                Phaser.Math.DegToRad(-30),
                SKY.light,
                0.85,
            );

            const roof = {
                x: cabLeft - 12,
                y: cabTop - 16,
                width: length * 0.38 + 24,
                height: 26,
            };

            surface(
                context,
                () => roundedRect(context, roof.x, roof.y, roof.width, 26, 10),
                roof,
                t.shade,
                { depth: 0.7 },
            );

            // The lamp, brass with a soft glow.
            const lamp = {
                x: length * 0.07,
                y: boilerTop + boilerHeight * 0.3,
            };
            const lampRadius = w * 0.05;

            context.save();
            context.shadowColor = rgba(BRASS.light, 0.9);
            context.shadowBlur = lampRadius * 1.6;
            surface(
                context,
                () => {
                    context.beginPath();
                    context.arc(lamp.x, lamp.y, lampRadius, 0, Math.PI * 2);
                },
                {
                    x: lamp.x - lampRadius,
                    y: lamp.y - lampRadius,
                    width: lampRadius * 2,
                    height: lampRadius * 2,
                },
                BRASS,
            );
            context.restore();
        },
    );
}

/** A soft puff of steam, white cloud tinted violet at its edge: painted once. */
function puffTexture(scene: Phaser.Scene): string {
    return texture(scene, 'train-puff', 64, 64, (context) => {
        const cloud = context.createRadialGradient(26, 24, 0, 32, 32, 32);

        cloud.addColorStop(0, rgba(CLOUD.light, 0.95));
        cloud.addColorStop(0.55, rgba(CLOUD.base, 0.85));
        cloud.addColorStop(0.85, rgba(CLOUD.shade, 0.5));
        cloud.addColorStop(1, rgba(CLOUD.shade, 0));
        context.fillStyle = cloud;
        context.beginPath();
        context.arc(32, 32, 32, 0, Math.PI * 2);
        context.fill();
    });
}

/** The solved unit's sign, painted once per colour and size. */
function signTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
    height: number,
): string {
    const w = step(width);
    const h = step(height);

    return texture(
        scene,
        `train-sign-${css(colour)}-${w}x${h}`,
        w + PAD * 2,
        h + PAD * 2,
        (context) =>
            paintPlate(
                context,
                { x: PAD, y: PAD, width: w, height: h },
                {
                    colour,
                    radius: 18,
                    inset: { side: 8, top: 8, bottom: 8 },
                    face: PAPER,
                    faceRadius: 14,
                    shadow: { blur: 10, drop: 5 },
                },
            ),
    );
}

/** The top of the slot train's rails: the sleepers end at the band's bottom. */
function railOf(options: Pick<SlotRowOptions, 'band'>): number {
    return options.band.bottom - 36;
}

type Geometry = {
    rail: number;
    /** A wagon's width; its body is `proportions(width)` high. */
    width: number;
    body: number;
    radius: number;
    /** The centre of a wagon's body. */
    y: number;
    locomotive: number;
    slots: number[];
    tail: number | null;
    left: number;
    right: number;
};

export function trainGeometry(options: SlotRowOptions): Geometry {
    const { count, tail } = options;
    const n = Math.max(count, 1);
    const rail = railOf(options);
    const cars =
        LOCOMOTIVE + n * (1 + GAP) + (tail === null ? 0 : END_CAR + GAP);
    // The locomotive's cab stands about a third of a wagon above the body.
    const tall = (rail - options.band.top - ROOF - 4) / (0.74 + 0.3);
    const width = Math.min(300, (1920 - MARGIN * 2) / cars, tall);
    const { height: body, radius } = proportions(width);
    const total = width * cars;
    const left = 960 - total / 2;
    const first = left + width * (LOCOMOTIVE + GAP);

    return {
        rail,
        width,
        body,
        radius,
        y: rail - body / 2 - radius,
        locomotive: left + (width * LOCOMOTIVE) / 2,
        slots: Array.from(
            { length: n },
            (_, index) => first + width / 2 + index * width * (1 + GAP),
        ),
        tail:
            tail === null
                ? null
                : first + n * width * (1 + GAP) + (width * END_CAR) / 2,
        left,
        right: left + total,
    };
}

class Train implements SlotView {
    private readonly root: Phaser.GameObjects.Container;
    private readonly geometry: Geometry;
    private readonly wheels: {
        wheel: Phaser.GameObjects.Image;
        radius: number;
    }[] = [];
    private readonly shells: Phaser.GameObjects.Image[];
    private readonly glow: Phaser.GameObjects.Image;
    private readonly palette: number[];
    private readonly colour: number;
    /** The texture width of a wagon: the geometry's, rounded up. */
    private readonly painted: number;
    private filled = 0;
    private gone = false;
    /** Where the steam comes out, inside the train. */
    private chimneyTop = { x: 0, y: 0 };
    private chugging: Phaser.Tweens.Tween | null = null;
    private puffing: Phaser.Time.TimerEvent | null = null;

    constructor(
        private readonly scene: Phaser.Scene,
        private readonly options: SlotRowOptions,
    ) {
        this.geometry = trainGeometry(options);
        this.palette = options.palette;
        this.colour = options.palette[0];
        this.painted = step(this.geometry.width);
        this.root = scene.add.container(0, 0).setDepth(1);

        const { slots, tail, width, body } = this.geometry;

        // The next wagon to fill glows behind it, so the class sees where the piece goes.
        this.glow = scene.add.image(
            0,
            0,
            glowTexture(scene, this.colour, width, body + ROOF, 16, SPREAD),
        );
        this.glow.setScale(width / step(width)).setAlpha(0);
        this.root.add(this.glow);
        this.couplers();
        this.locomotive();
        this.shells = slots.map((x, index) => this.car(x, index));

        if (tail !== null && options.tail !== null) {
            this.endCar(tail, options.tail);
        }

        this.draw();
    }

    spot(index: number): SlotSpot {
        const { slots, y, width, body, radius } = this.geometry;

        return {
            x: this.root.x + slots[index],
            y: this.root.y + y,
            width,
            // A wagon tile's copy covers the empty wagon, wheels on its wheels.
            height: body + radius + ROOF,
        };
    }

    enter(delay: number): void {
        const distance = 1920 - this.geometry.left + 40;

        this.root.x = distance;
        this.roll(0, delay, 1500, 'Cubic.easeOut', () => {
            this.puff(3);
            this.chug();
        });
        this.scene.tweens.add({
            targets: this.glow,
            alpha: { from: 0, to: 0.8 },
            delay: delay + 1500,
            duration: 650,
            yoyo: true,
            repeat: -1,
            ease: 'Sine.easeInOut',
        });
    }

    progress(filled: number): void {
        this.filled = filled;
        this.draw();
    }

    complete(text: string): void {
        if (this.gone) {
            return;
        }

        const { scene } = this;
        const { slots, y, width, body } = this.geometry;

        scene.tweens.killTweensOf(this.glow);
        this.glow.setVisible(false);
        this.filled = slots.length;
        this.draw();

        // One long sign riding on the wagons.
        const from = slots[0] - width / 2;
        const to = slots[slots.length - 1] + width / 2;
        const height = body * 0.86;
        const label = scene.add
            .text(0, 0, text, {
                fontFamily: 'Andika',
                fontStyle: 'bold',
                fontSize: `${Math.round(Math.min(height * 0.72, 150))}px`,
                color: textOn(hex(PAPER)),
                padding: { x: 8, y: 12 },
            })
            .setOrigin(0.5);
        const room = Math.max(to - from - 40, 1920 - MARGIN * 4);

        if (label.width > room) {
            label.setScale(room / label.width);
        }

        const signWidth = Math.max(to - from, label.displayWidth + 80);
        const sign = scene.add.image(
            0,
            0,
            signTexture(scene, this.colour, signWidth, height),
        );

        sign.setScale(signWidth / step(signWidth), height / step(height));

        const whole = scene.add.container((from + to) / 2, y - ROOF / 2, [
            sign,
            label,
        ]);

        whole.setScale(0.4, 0).setAlpha(0);
        this.root.add(whole);
        scene.tweens.add({
            targets: whole,
            scaleX: 1,
            scaleY: 1,
            alpha: 1,
            delay: 120,
            duration: 420,
            ease: 'Back.easeOut',
        });
        scene.time.delayedCall(200, () => this.puff(4));
    }

    leave(delay: number, gone: () => void): void {
        if (this.gone) {
            return;
        }

        this.gone = true;
        this.scene.tweens.killTweensOf(this.glow);
        this.stopChugging();
        this.scene.time.delayedCall(delay, () => this.puff(2));
        // Quick, so the next round's train can come in behind it.
        this.roll(
            this.root.x - this.geometry.right - 60,
            delay,
            1000,
            'Sine.easeIn',
            () => {
                this.destroy();
                gone();
            },
        );
    }

    destroy(): void {
        this.gone = true;
        this.stopChugging();
        this.scene.tweens.killTweensOf([this.root, this.glow]);
        this.root.destroy();
    }

    /** A filled wagon is a lit surface in its colour, an empty one a pale well; the glow sits behind the next. */
    private draw(): void {
        this.shells.forEach((shell, index) => {
            shell.setTexture(
                shellTexture(
                    this.scene,
                    this.carColour(index),
                    this.painted,
                    index < this.filled,
                ),
            );
        });

        const next = this.geometry.slots[this.filled];

        this.glow.setVisible(next !== undefined);

        if (next !== undefined) {
            this.glow.setPosition(next, this.geometry.y - ROOF / 2);
        }
    }

    private carColour(index: number): number {
        return this.palette[(index + 1) % this.palette.length];
    }

    /** The bars between the cars, behind them: thin, in the guide's violet black. */
    private couplers(): void {
        const { locomotive, slots, tail, width, y, body } = this.geometry;
        const bar = this.scene.add.graphics();
        const ends: [number, number][] = [];
        let from = locomotive + (width * LOCOMOTIVE) / 2;

        for (const x of [...slots, ...(tail === null ? [] : [tail])]) {
            const half = x === tail ? (width * END_CAR) / 2 : width / 2;

            ends.push([from, x - half]);
            from = x + half;
        }

        for (const [start, end] of ends) {
            bar.fillStyle(TYRE.base, 1);
            bar.fillRect(
                start - 2,
                y + body * 0.18,
                end - start + 4,
                body * 0.1,
            );
            bar.fillStyle(TYRE.light, 1);
            bar.fillRect(start - 2, y + body * 0.18, end - start + 4, 2);
        }

        this.root.add(bar);
    }

    /** An empty wagon on its wheels; `draw()` fills it in. */
    private car(x: number, index: number): Phaser.GameObjects.Image {
        const { y, body, radius, width } = this.geometry;
        const shell = sized(
            this.scene.add.image(
                x,
                y,
                shellTexture(
                    this.scene,
                    this.carColour(index),
                    this.painted,
                    false,
                ),
            ),
            width,
            this.painted,
        );

        this.root.add(shell);

        for (const side of [-1, 1]) {
            this.addWheel(x + side * width * 0.3, y + body / 2, radius);
        }

        return shell;
    }

    /** The short car at the end that carries the sentence's last mark. */
    private endCar(x: number, mark: string): void {
        const { width, body, radius, y } = this.geometry;
        const deck = body * 0.55;
        const car = sized(
            this.scene.add.image(
                x,
                y + body / 2 - deck / 2,
                endCarTexture(this.scene, this.colour, this.painted),
            ),
            width,
            this.painted,
        );

        this.root.add(car);
        this.addWheel(x, y + body / 2, radius * 0.85);

        const label = this.scene.add
            .text(x, y + body / 2 - deck / 2 - 6, mark, {
                fontFamily: 'Andika',
                fontStyle: 'bold',
                fontSize: `${Math.round(body * 0.8)}px`,
                color: hex(shade(this.colour, -0.6)),
                padding: { x: 6, y: 10 },
            })
            .setOrigin(0.5, 0.8);

        this.root.add(label);
    }

    /** The locomotive, facing left, on a small front wheel and two big ones joined by a rod. */
    private locomotive(): void {
        const { width, radius, y, rail } = this.geometry;
        const length = width * LOCOMOTIVE;
        const x0 = this.geometry.locomotive - length / 2;
        const parts = locomotiveParts(this.painted);
        const scale = width / this.painted;
        const body = this.scene.add
            .image(
                x0 - parts.left * scale,
                y - parts.top * scale,
                locomotiveTexture(this.scene, this.colour, this.painted),
            )
            .setOrigin(0, 0)
            .setScale(scale);

        this.root.add(body);

        const boilerTop = y + parts.boilerTop * scale;

        this.chimneyTop = {
            x: x0 + parts.chimney * scale + width * 0.06,
            y: boilerTop - width * 0.27,
        };

        // A small wheel at the front, two big ones under the cab, and the rod.
        this.addWheel(x0 + length * 0.2, rail - radius * 0.8, radius * 0.8);

        const big = radius * 1.35;
        const drive = [x0 + length * 0.52, x0 + length * 0.8];

        for (const x of drive) {
            this.addWheel(x, rail - big, big);
        }

        const rod = this.scene.add.graphics();

        rod.fillStyle(METAL.base, 1);
        rod.fillRoundedRect(
            drive[0] - 8,
            rail - big - 6,
            drive[1] - drive[0] + 16,
            12,
            6,
        );
        rod.fillStyle(METAL.light, 1);
        rod.fillRoundedRect(
            drive[0] - 4,
            rail - big - 5,
            drive[1] - drive[0] + 8,
            3,
            1.5,
        );
        this.root.add(rod);
    }

    private addWheel(x: number, y: number, radius: number): void {
        const turning = wheel(this.scene, x, y, radius);

        this.wheels.push({ wheel: turning, radius });
        this.root.add(turning);
    }

    /** Puffs of soft steam from the chimney, rising and fading. */
    private puff(count: number): void {
        for (let index = 0; index < count; index++) {
            this.scene.time.delayedCall(index * 160, () => {
                if (!this.root.active) {
                    return;
                }

                const cloud = this.scene.add.image(
                    this.root.x + this.chimneyTop.x,
                    this.root.y + this.chimneyTop.y,
                    puffTexture(this.scene),
                );
                const size = (this.geometry.width * 0.16) / cloud.width;

                cloud.setScale(size).setDepth(1.5);
                this.scene.tweens.add({
                    targets: cloud,
                    y: cloud.y - 110,
                    x: cloud.x + Phaser.Math.Between(10, 60),
                    scale: size * 2.6,
                    alpha: 0,
                    duration: 1100,
                    ease: 'Sine.easeOut',
                    onComplete: () => cloud.destroy(),
                });
            });
        }
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
                const travelled = this.root.x - from;

                for (const { wheel: turning, radius } of this.wheels) {
                    turning.setRotation(travelled / radius);
                }
            },
            onComplete: done,
        });
    }

    private chug(): void {
        if (this.gone) {
            return;
        }

        this.chugging = this.scene.tweens.add({
            targets: this.root,
            y: -4,
            duration: 420,
            yoyo: true,
            repeat: -1,
            ease: 'Sine.easeInOut',
        });
        this.puffing = this.scene.time.addEvent({
            delay: 2600,
            loop: true,
            callback: () => this.puff(1),
        });
    }

    private stopChugging(): void {
        this.chugging?.stop();
        this.chugging = null;
        this.puffing?.remove();
        this.puffing = null;
        this.root.y = 0;
    }
}

export const wagonSlots: SlotStyle = {
    scenery: (scene, band) => drawRails(scene, railOf({ band })),
    create: (scene, options) => new Train(scene, options),
};
