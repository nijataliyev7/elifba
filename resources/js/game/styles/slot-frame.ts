/**
 * The `frame` slot style (docs/architecture.md §2): plain frames in a row,
 * one per piece of the answer, with the fixed `.`, `?` or `!` after the
 * last. The frames pop in, the next one to fill glows, a copy of each
 * correct tile lands in its frame (the scene keeps it there), and once the
 * round is solved the frames give way to one plate with the unit whole —
 * «TUT», «nənə», «Tar gözəldir.» — which floats up when the round is over.
 *
 * Drawn in ADR 014's Fluent manner, each look painted once per colour and
 * size (`frameTexture()`, `plateTexture()`), never with an outline: an
 * empty frame is a pale well set into the board, its upper inner edges in
 * shade; a filled one is a white face set into a surface in the pack
 * colour, lit from the top left, with a soft shadow in the colour's deep
 * shade; the next frame to fill has a soft glow behind it.
 */
import type Phaser from 'phaser';
import { textOn } from '../palette';
import {
    css,
    glowTexture,
    paintPlate,
    paintRecess,
    step,
    texture,
} from './fluent';
import { hex, shade } from './item';
import type { SlotRowOptions, SlotSpot, SlotStyle, SlotView } from './types';

const MARGIN = 120;
const GAP = 34;
/** Space between a frame's edge and the copy in it. */
const PAD = 16;
/** The coloured rim round a filled frame's face. */
const RIM = 8;
const RADIUS = 22;
const PAPER = 0xffffff;
/** Room round a frame in its texture, for the shadow. */
const SHADOW = 16;

/** A frame, empty (a well) or filled (a plate), painted once per colour and size. */
function frameTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
    height: number,
    filled: boolean,
): string {
    const w = step(width);
    const h = step(height);
    const key = `frame-${filled ? 'filled' : 'empty'}-${css(colour)}-${w}x${h}`;

    return texture(scene, key, w + SHADOW * 2, h + SHADOW * 2, (context) => {
        const box = { x: SHADOW, y: SHADOW, width: w, height: h };

        if (filled) {
            paintPlate(context, box, {
                colour,
                radius: RADIUS,
                inset: { side: RIM, top: RIM, bottom: RIM },
                face: PAPER,
                faceRadius: RADIUS - RIM / 2,
                shadow: { blur: 14, drop: 6 },
            });
        } else {
            paintRecess(context, box, colour, RADIUS);
        }
    });
}

/** The solved unit's plate, painted once per colour and size. */
function plateTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
    height: number,
): string {
    const w = step(width);
    const h = step(height);

    return texture(
        scene,
        `frame-plate-${css(colour)}-${w}x${h}`,
        w + SHADOW * 4,
        h + SHADOW * 4,
        (context) =>
            paintPlate(
                context,
                { x: SHADOW * 2, y: SHADOW * 2, width: w, height: h },
                {
                    colour,
                    radius: 30,
                    inset: { side: RIM + 2, top: RIM + 2, bottom: RIM + 2 },
                    face: PAPER,
                    faceRadius: 30 - RIM / 2,
                    shadow: { blur: 22, drop: 10 },
                },
            ),
    );
}

/** An image of a texture painted at a size rounded up (`step()`), shown at `width` × `height`. */
function fitted(
    image: Phaser.GameObjects.Image,
    width: number,
    height: number,
    room: number,
): Phaser.GameObjects.Image {
    return image.setScale(
        width / (image.width - room * 2),
        height / (image.height - room * 2),
    );
}

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
        (height - PAD * 2 - RIM) / aspect,
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
        paper: Phaser.GameObjects.Image;
    }[];
    private readonly glow: Phaser.GameObjects.Image;
    private readonly tail: Phaser.GameObjects.Text | null = null;
    private readonly colour: number;
    private filled = 0;
    private gone = false;

    constructor(
        private readonly scene: Phaser.Scene,
        options: SlotRowOptions,
    ) {
        this.geometry = frameGeometry(options);
        this.colour = options.palette[0];

        const { xs, y, frame } = this.geometry;

        this.root = scene.add.container(0, 0).setDepth(1);

        // The next frame to fill glows behind it, so the class sees where the piece goes.
        const spread = 18;

        this.glow = fitted(
            scene.add.image(
                0,
                0,
                glowTexture(
                    scene,
                    this.colour,
                    frame.width,
                    frame.height,
                    RADIUS,
                    spread,
                ),
            ),
            frame.width,
            frame.height,
            spread * 2,
        );
        this.glow.setAlpha(0);
        this.root.add(this.glow);
        this.frames = xs.map((x) => {
            const paper = scene.add.image(0, 0, this.look(false));
            const holder = scene.add.container(x, y, [
                fitted(paper, frame.width, frame.height, SHADOW),
            ]);

            this.root.add(holder);

            return { holder, paper };
        });

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
            alpha: { from: 0, to: 0.75 },
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
        const plate = fitted(
            scene.add.image(
                0,
                0,
                plateTexture(scene, this.colour, width, height),
            ),
            width,
            height,
            SHADOW * 2,
        );

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

    /** A frame's texture: a pale well while empty, a plate in the pack colour once filled. */
    private look(filled: boolean): string {
        const { frame } = this.geometry;

        return frameTexture(
            this.scene,
            this.colour,
            frame.width,
            frame.height,
            filled,
        );
    }

    /** Empty frames are wells; filled ones take the pack colour; the glow sits behind the next. */
    private draw(): void {
        const { xs } = this.geometry;

        this.frames.forEach(({ paper }, index) => {
            paper.setTexture(this.look(index < this.filled));
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
