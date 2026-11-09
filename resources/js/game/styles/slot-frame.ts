/**
 * The `frame` slot style (docs/architecture.md §2): plain frames in a row,
 * one per piece of the answer, with the fixed `.`, `?` or `!` after the
 * last. The frames pop in, the next one to fill glows, a copy of each
 * correct tile lands in its frame (the scene keeps it there), and once the
 * round is solved the frames give way to one plate with the unit whole —
 * «TUT», «nənə», «Tar gözəldir.» — which floats up when the round is over.
 */
import type Phaser from 'phaser';
import { textOn } from '../palette';
import { hex, shade } from './item';
import type { SlotRowOptions, SlotSpot, SlotStyle, SlotView } from './types';

const MARGIN = 120;
const GAP = 34;
/** Space between a frame's edge and the copy in it. */
const PAD = 16;
const STROKE = 8;
const RADIUS = 22;
const PAPER = 0xffffff;

type Geometry = {
    xs: number[];
    y: number;
    inner: { width: number; height: number };
    frame: { width: number; height: number };
    tail: { x: number; size: number } | null;
};

/** Frames that hug the pack's items, as big as the band and the board allow. */
export function frameGeometry(options: SlotRowOptions): Geometry {
    const { count, band, aspect, tail } = options;
    const n = Math.max(count, 1);
    const height = band.bottom - band.top;
    const tailRoom = tail === null ? 0 : Math.min(110, height * 0.45);
    const across = (1920 - MARGIN * 2 - tailRoom - GAP * (n - 1)) / n;
    let innerWidth = Math.min(
        (height - PAD * 2 - STROKE) / aspect,
        across - PAD * 2,
        380,
    );
    const innerHeight = innerWidth * aspect;

    innerWidth = Math.max(innerWidth, 40);

    const frame = {
        width: innerWidth + PAD * 2,
        height: innerHeight + PAD * 2,
    };
    const total = n * frame.width + (n - 1) * GAP + tailRoom;
    const left = 960 - total / 2;

    return {
        xs: Array.from(
            { length: n },
            (_, index) => left + frame.width / 2 + index * (frame.width + GAP),
        ),
        y: (band.top + band.bottom) / 2,
        inner: { width: innerWidth, height: innerHeight },
        frame,
        tail:
            tail === null
                ? null
                : {
                      x: left + n * frame.width + (n - 1) * GAP + tailRoom / 2,
                      size: Math.min(frame.height * 0.62, 160),
                  },
    };
}

class FrameRow implements SlotView {
    private readonly root: Phaser.GameObjects.Container;
    private readonly geometry: Geometry;
    private readonly frames: {
        holder: Phaser.GameObjects.Container;
        paper: Phaser.GameObjects.Graphics;
    }[];
    private readonly glow: Phaser.GameObjects.Graphics;
    private readonly tail: Phaser.GameObjects.Text | null = null;
    private readonly colour: number;
    private filled = 0;
    private gone = false;

    constructor(
        private readonly scene: Phaser.Scene,
        options: SlotRowOptions,
    ) {
        this.geometry = frameGeometry(options);
        this.colour = shade(options.palette[0], -0.12);

        const { xs, y, frame } = this.geometry;

        this.root = scene.add.container(0, 0).setDepth(1);
        this.frames = xs.map((x) => {
            const paper = scene.add.graphics();
            const holder = scene.add.container(x, y, [paper]);

            this.root.add(holder);

            return { holder, paper };
        });

        // The next frame to fill breathes, so the class sees where the piece goes.
        this.glow = scene.add.graphics();
        this.glow.lineStyle(STROKE + 6, this.colour, 1);
        this.glow.strokeRoundedRect(
            -frame.width / 2 - 8,
            -frame.height / 2 - 8,
            frame.width + 16,
            frame.height + 16,
            RADIUS + 6,
        );
        this.glow.setAlpha(0);
        this.root.add(this.glow);

        if (options.tail !== null && this.geometry.tail !== null) {
            this.tail = scene.add
                .text(
                    this.geometry.tail.x,
                    y + frame.height / 2,
                    options.tail,
                    {
                        fontFamily: 'Andika',
                        fontStyle: 'bold',
                        fontSize: `${Math.round(this.geometry.tail.size)}px`,
                        color: hex(shade(options.palette[0], -0.55)),
                        padding: { x: 8, y: 12 },
                    },
                )
                .setOrigin(0.5, 0.85);
            this.root.add(this.tail);
        }

        this.draw();
    }

    spot(index: number): SlotSpot {
        const { xs, y, inner } = this.geometry;

        return {
            x: this.root.x + xs[index],
            y: this.root.y + y,
            width: inner.width,
            height: inner.height,
        };
    }

    enter(delay: number): void {
        this.frames.forEach(({ holder }, index) => {
            holder.setScale(0);
            this.scene.tweens.add({
                targets: holder,
                scale: 1,
                delay: delay + index * 90,
                duration: 360,
                ease: 'Back.easeOut',
            });
        });

        if (this.tail !== null) {
            this.tail.setAlpha(0);
            this.scene.tweens.add({
                targets: this.tail,
                alpha: 1,
                delay: delay + this.frames.length * 90 + 120,
                duration: 300,
            });
        }

        this.scene.tweens.add({
            targets: this.glow,
            alpha: { from: 0, to: 0.35 },
            delay: delay + this.frames.length * 90 + 200,
            duration: 700,
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
        const { y, frame } = this.geometry;

        scene.tweens.killTweensOf(this.glow);
        this.glow.setVisible(false);
        scene.tweens.add({
            targets: [
                ...this.frames.map(({ holder }) => holder),
                ...(this.tail === null ? [] : [this.tail]),
            ],
            alpha: 0,
            scale: 0.9,
            duration: 220,
        });

        const height = Math.min(frame.height + 20, 300);
        const label = scene.add
            .text(0, 0, text, {
                fontFamily: 'Andika',
                fontStyle: 'bold',
                fontSize: `${Math.round(height * 0.6)}px`,
                color: textOn(hex(PAPER)),
                padding: { x: 8, y: 12 },
            })
            .setOrigin(0.5);
        const room = 1920 - MARGIN * 2 - 80;

        if (label.width > room) {
            label.setScale(room / label.width);
        }

        const width = Math.max(
            label.displayWidth + 100,
            Math.min(frame.width * 2, room),
        );
        const plate = scene.add.graphics();

        plate.fillStyle(0x1f2937, 0.12);
        plate.fillRoundedRect(
            -width / 2 + 8,
            -height / 2 + 12,
            width,
            height,
            30,
        );
        plate.fillStyle(PAPER, 1);
        plate.fillRoundedRect(-width / 2, -height / 2, width, height, 30);
        plate.lineStyle(STROKE + 2, this.colour, 1);
        plate.strokeRoundedRect(-width / 2, -height / 2, width, height, 30);

        const whole = scene.add.container(960, y, [plate, label]);

        whole.setScale(0.5).setAlpha(0);
        this.root.add(whole);
        scene.tweens.add({
            targets: whole,
            scale: 1,
            alpha: 1,
            delay: 120,
            duration: 420,
            ease: 'Back.easeOut',
        });
    }

    leave(delay: number, gone: () => void): void {
        if (this.gone) {
            return;
        }

        this.gone = true;
        this.scene.tweens.killTweensOf(this.glow);
        this.scene.tweens.add({
            targets: this.root,
            y: -120,
            alpha: 0,
            delay,
            duration: 650,
            ease: 'Cubic.easeIn',
            onComplete: () => {
                this.destroy();
                gone();
            },
        });
    }

    destroy(): void {
        this.gone = true;
        this.scene.tweens.killTweensOf(this.root);
        this.scene.tweens.killTweensOf(this.glow);
        this.root.destroy();
    }

    /** Empty frames are pale; filled ones take the pack colour; the glow sits on the next. */
    private draw(): void {
        const { frame, xs } = this.geometry;
        const left = -frame.width / 2;
        const top = -frame.height / 2;

        this.frames.forEach(({ paper }, index) => {
            const filled = index < this.filled;

            paper.clear();
            paper.fillStyle(PAPER, filled ? 0.95 : 0.8);
            paper.fillRoundedRect(left, top, frame.width, frame.height, RADIUS);
            paper.lineStyle(
                STROKE,
                filled ? this.colour : shade(this.colour, 0.45),
                1,
            );
            paper.strokeRoundedRect(
                left,
                top,
                frame.width,
                frame.height,
                RADIUS,
            );
        });

        const next = xs[this.filled];

        this.glow.setVisible(next !== undefined);

        if (next !== undefined) {
            this.glow.setPosition(next, this.geometry.y);
        }
    }
}

export const frame: SlotStyle = {
    create: (scene, options) => new FrameRow(scene, options),
};
