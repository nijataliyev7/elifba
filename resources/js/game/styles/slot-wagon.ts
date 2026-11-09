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
 */
import Phaser from 'phaser';
import { textOn } from '../palette';
import { hex, shade } from './item';
import type { SlotRowOptions, SlotSpot, SlotStyle, SlotView } from './types';
import { drawRails, proportions, wheel } from './wagon';

const MARGIN = 80;
/** Space between two cars, where the couplers are. */
const GAP = 0.14;
const LOCOMOTIVE = 1.2;
const END_CAR = 0.5;
const ROOF = 18;
const CHASSIS = 0x374151;
const PAPER = 0xffffff;

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
        wheel: Phaser.GameObjects.Graphics;
        radius: number;
    }[] = [];
    private readonly shells: Phaser.GameObjects.Graphics[];
    private readonly glow: Phaser.GameObjects.Graphics;
    private readonly palette: number[];
    private readonly colour: number;
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
        this.root = scene.add.container(0, 0).setDepth(1);

        const { slots, tail } = this.geometry;

        this.couplers();
        this.locomotive();
        this.shells = slots.map((x) => this.car(x, this.geometry.width));

        if (tail !== null && options.tail !== null) {
            this.endCar(tail, options.tail);
        }

        // The next wagon to fill glows, so the class sees where the piece goes.
        const { width, body } = this.geometry;

        this.glow = scene.add.graphics();
        this.glow.lineStyle(12, shade(this.colour, -0.1), 1);
        this.glow.strokeRoundedRect(
            -width / 2 - 10,
            -body / 2 - ROOF - 10,
            width + 20,
            body + ROOF + 20,
            20,
        );
        this.glow.setAlpha(0);
        this.root.add(this.glow);
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
            alpha: { from: 0, to: 0.45 },
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
        const sign = scene.add.graphics();

        sign.fillStyle(PAPER, 1);
        sign.fillRoundedRect(
            -signWidth / 2,
            -height / 2,
            signWidth,
            height,
            18,
        );
        sign.lineStyle(8, shade(this.colour, -0.3), 1);
        sign.strokeRoundedRect(
            -signWidth / 2,
            -height / 2,
            signWidth,
            height,
            18,
        );

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

    private draw(): void {
        const { width, body } = this.geometry;
        const left = -width / 2;
        const top = -body / 2;

        this.shells.forEach((shell, index) => {
            const filled = index < this.filled;
            const colour = this.palette[(index + 1) % this.palette.length];

            shell.clear();

            // A filled wagon is solid; an empty one is a pale outline.
            shell.fillStyle(filled ? colour : PAPER, filled ? 1 : 0.85);
            shell.fillRoundedRect(left, top, width, body, 14);
            shell.lineStyle(6, shade(colour, -0.28), filled ? 1 : 0.55);
            shell.strokeRoundedRect(left, top, width, body, 14);
        });

        const next = this.geometry.slots[this.filled];

        this.glow.setVisible(next !== undefined);

        if (next !== undefined) {
            this.glow.setPosition(next, this.geometry.y);
        }
    }

    /** The bars between the cars, behind them. */
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

        bar.fillStyle(CHASSIS, 1);

        for (const [start, end] of ends) {
            bar.fillRect(
                start - 2,
                y + body * 0.18,
                end - start + 4,
                body * 0.1,
            );
        }

        this.root.add(bar);
    }

    /** An empty wagon: the chassis and wheels, and a shell `draw()` fills in. */
    private car(x: number, width: number): Phaser.GameObjects.Graphics {
        const { y, body, radius } = this.geometry;
        const chassis = this.scene.add.graphics({ x, y });

        chassis.fillStyle(CHASSIS, 1);
        chassis.fillRect(-width / 2, body / 2 - 18, width, 18);

        const shell = this.scene.add.graphics({ x, y });

        this.root.add([chassis, shell]);

        for (const side of [-1, 1]) {
            this.addWheel(x + side * width * 0.3, y + body / 2, radius);
        }

        return shell;
    }

    /** The short car at the end that carries the sentence's last mark. */
    private endCar(x: number, mark: string): void {
        const { width, body, radius, y } = this.geometry;
        const car = width * END_CAR;
        const g = this.scene.add.graphics({ x, y });
        const deck = body * 0.55;

        g.fillStyle(CHASSIS, 1);
        g.fillRect(-car / 2, body / 2 - 18, car, 18);
        g.fillStyle(shade(this.colour, 0.75), 1);
        g.fillRoundedRect(-car / 2, body / 2 - deck, car, deck - 10, 10);
        g.lineStyle(5, shade(this.colour, -0.28), 1);
        g.strokeRoundedRect(-car / 2, body / 2 - deck, car, deck - 10, 10);
        this.root.add(g);
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

    /** The locomotive, facing left: cowcatcher, chimney, boiler, cab, three wheels. */
    private locomotive(): void {
        const { width, body, radius, y, rail } = this.geometry;
        const length = width * LOCOMOTIVE;
        const x0 = this.geometry.locomotive - length / 2;
        const g = this.scene.add.graphics();
        const main = this.colour;
        const dark = shade(main, -0.35);
        const boilerTop = y - body * 0.28;
        const boilerHeight = body * 0.62;
        const cabLeft = x0 + length * 0.6;
        const cabTop = y - body / 2 - width * 0.3;

        // Chassis.
        g.fillStyle(CHASSIS, 1);
        g.fillRect(x0 + length * 0.06, y + body / 2 - 20, length * 0.94, 20);

        // Boiler and the bands round it.
        g.fillStyle(main, 1);
        g.fillRoundedRect(
            x0 + length * 0.08,
            boilerTop,
            length * 0.56,
            boilerHeight,
            boilerHeight * 0.3,
        );
        g.fillStyle(dark, 1);

        for (const at of [0.22, 0.4]) {
            g.fillRect(x0 + length * at, boilerTop, 10, boilerHeight);
        }

        // Chimney with its crown, and the dome.
        const chimney = x0 + length * 0.16;

        g.fillStyle(CHASSIS, 1);
        g.fillRect(
            chimney,
            boilerTop - width * 0.22,
            width * 0.12,
            width * 0.24,
        );
        g.fillRoundedRect(
            chimney - width * 0.03,
            boilerTop - width * 0.27,
            width * 0.18,
            width * 0.07,
            6,
        );
        g.fillStyle(dark, 1);
        g.fillEllipse(
            x0 + length * 0.44,
            boilerTop,
            width * 0.16,
            width * 0.14,
        );

        // Cab, its roof and window.
        g.fillStyle(main, 1);
        g.fillRoundedRect(
            cabLeft,
            cabTop,
            length * 0.38,
            y + body / 2 - cabTop - 10,
            10,
        );
        g.fillStyle(dark, 1);
        g.fillRoundedRect(
            cabLeft - 12,
            cabTop - 16,
            length * 0.38 + 24,
            26,
            10,
        );
        g.fillStyle(PAPER, 0.92);
        g.fillRoundedRect(
            cabLeft + length * 0.07,
            cabTop + 26,
            length * 0.24,
            body * 0.36,
            8,
        );
        g.lineStyle(6, dark, 1);
        g.strokeRoundedRect(
            cabLeft,
            cabTop,
            length * 0.38,
            y + body / 2 - cabTop - 10,
            10,
        );

        // Lamp and cowcatcher at the front.
        g.fillStyle(0xf6c445, 1);
        g.fillCircle(
            x0 + length * 0.07,
            boilerTop + boilerHeight * 0.3,
            width * 0.05,
        );
        g.fillStyle(CHASSIS, 1);
        g.fillTriangle(
            x0 - length * 0.02,
            rail - 4,
            x0 + length * 0.1,
            rail - 4,
            x0 + length * 0.1,
            y + body / 2 - 22,
        );

        this.root.add(g);
        this.chimneyTop = {
            x: chimney + width * 0.06,
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

        rod.fillStyle(0x9ca3af, 1);
        rod.fillRoundedRect(
            drive[0] - 8,
            rail - big - 6,
            drive[1] - drive[0] + 16,
            12,
            6,
        );
        this.root.add(rod);
    }

    private addWheel(x: number, y: number, radius: number): void {
        const turning = wheel(this.scene, x, y, radius);

        this.wheels.push({ wheel: turning, radius });
        this.root.add(turning);
    }

    /** Puffs of steam from the chimney, rising and fading. */
    private puff(count: number): void {
        for (let index = 0; index < count; index++) {
            this.scene.time.delayedCall(index * 160, () => {
                if (!this.root.active) {
                    return;
                }

                const cloud = this.scene.add.circle(
                    this.root.x + this.chimneyTop.x,
                    this.root.y + this.chimneyTop.y,
                    this.geometry.width * 0.08,
                    PAPER,
                    0.9,
                );

                cloud.setDepth(1.5);
                this.scene.tweens.add({
                    targets: cloud,
                    y: cloud.y - 110,
                    x: cloud.x + Phaser.Math.Between(10, 60),
                    scale: 2.6,
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
