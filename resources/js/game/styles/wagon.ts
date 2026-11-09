/**
 * The `wagon` item style (docs/architecture.md §2): a goods wagon in a pack
 * colour with its item's text on a light board, on two spoked wheels. The
 * wagons of a round stand in a row on one track (the style's scenery);
 * they roll in from the left with turning wheels, chug while they wait,
 * rattle on a miss and roll away to the right when their round is over.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
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
const RAIL = 0x6b7280;
const SLEEPER = 0x9ca3af;

/** The top of the rails under a band: the sleepers end at its bottom. */
function railIn(band: Band | undefined): number {
    return band === undefined ? RAIL_Y : band.bottom - 36;
}

/** Rails and sleepers across the board, their top at `y`. */
export function drawRails(scene: Phaser.Scene, y: number): void {
    const track = scene.add.graphics();

    track.fillStyle(SLEEPER, 1);

    for (let x = 10; x < 1920; x += 64) {
        track.fillRect(x, y + 6, 34, 30);
    }

    track.fillStyle(RAIL, 1);
    track.fillRect(0, y, 1920, 12);
    track.setDepth(-1);
}

type WagonDrawing = ItemDrawing & {
    wheels: Phaser.GameObjects.Graphics[];
    radius: number;
};

/** Height of the body and radius of the wheels for a wagon `width` wide. */
export function proportions(width: number): { height: number; radius: number } {
    return { height: Math.round(width * 0.62), radius: width * 0.12 };
}

/** A spoked wheel centred on (x, y); rotate it to roll. */
export function wheel(
    scene: Phaser.Scene,
    x: number,
    y: number,
    radius: number,
): Phaser.GameObjects.Graphics {
    const g = scene.add.graphics({ x, y });

    g.fillStyle(0x374151, 1);
    g.fillCircle(0, 0, radius);
    g.fillStyle(0x9ca3af, 1);
    g.fillCircle(0, 0, radius * 0.72);
    g.lineStyle(radius * 0.14, 0x374151, 1);

    for (let spoke = 0; spoke < 3; spoke++) {
        const angle = (spoke * Math.PI) / 3;
        const dx = Math.cos(angle) * radius * 0.72;
        const dy = Math.sin(angle) * radius * 0.72;

        g.lineBetween(-dx, -dy, dx, dy);
    }

    g.fillStyle(0x374151, 1);
    g.fillCircle(0, 0, radius * 0.22);

    return g;
}

function drawWagon(
    scene: Phaser.Scene,
    text: string,
    look: ItemLook,
): WagonDrawing {
    const { width, colour } = look;
    const { height, radius } = proportions(width);
    const left = -width / 2;
    const top = -height / 2;
    const dark = shade(colour, -0.28);
    const board = shade(colour, 0.86);
    const body = scene.add.graphics();

    // Couplers, behind the body.
    body.fillStyle(0x374151, 1);
    body.fillRect(
        left - width * 0.07,
        height * 0.18,
        width * 1.14,
        height * 0.1,
    );

    // Roof, body, frame and the planks' lines.
    body.fillStyle(dark, 1);
    body.fillRoundedRect(left - 10, top - ROOF, width + 20, 30, 12);
    body.fillStyle(colour, 1);
    body.fillRoundedRect(left, top, width, height, 14);
    body.lineStyle(6, dark, 1);
    body.strokeRoundedRect(left, top, width, height, 14);
    body.fillStyle(dark, 1);
    body.fillRect(left, height / 2 - 18, width, 18);

    // The text's board.
    const boardWidth = width * 0.8;
    const boardHeight = height * 0.62;

    body.fillStyle(board, 1);
    body.fillRoundedRect(
        -boardWidth / 2,
        top + height * 0.12,
        boardWidth,
        boardHeight,
        10,
    );
    body.lineStyle(4, dark, 0.6);
    body.strokeRoundedRect(
        -boardWidth / 2,
        top + height * 0.12,
        boardWidth,
        boardHeight,
        10,
    );

    const label = itemLabel(
        scene,
        text,
        height * 0.44,
        board,
        boardWidth * 0.9,
    );

    label.setY(top + height * 0.12 + boardHeight / 2);

    const wheels = [-1, 1].map((side) =>
        wheel(scene, side * width * 0.3, height / 2, radius),
    );

    return {
        root: scene.add.container(0, 0, [body, ...wheels, label]),
        body,
        hitArea: {
            hitArea: new Phaser.Geom.Rectangle(
                left,
                top - ROOF,
                width,
                height + ROOF + radius,
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
    private readonly wheels: Phaser.GameObjects.Graphics[];
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
