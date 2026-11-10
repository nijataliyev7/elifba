/**
 * The `animal` item style (docs/architecture.md §2, "Figures"): an animal
 * from the pack's figures standing behind a plate with its item's text.
 * Animals hop up from below the board and land with a squash, hop now and
 * then while they wait, shake their head on a miss and hop away off the
 * bottom when their round is over. The animals pack uses `sparkle`, so an
 * animal hit correctly lifts away whole, never broken.
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import { drawFigure, figureLayout } from './figure';
import type { FigureShape } from './figure';
import { BoardItem } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const HOP = 16;

const SHAPE: FigureShape = {
    compact: 1.28,
    roomy: 1.5,
    maxFigure: 1.14,
    overlap: 0.2,
    maxWidth: 360,
    margin: 140,
    fill: 0.88,
    motion: { up: HOP, down: 0 },
    restY: () => 1080 * 0.5,
};

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    return drawFigure(scene, text, look, SHAPE);
}

export class Animal extends BoardItem {
    private hopping = false;

    constructor(
        scene: Phaser.Scene,
        item: RoundItem,
        options: ItemViewOptions,
    ) {
        const { x, y, width, height, colour, figure } = options;

        super(
            scene,
            item.id,
            colour,
            draw(scene, item.text, { width, height, colour, figure }),
            x,
            y,
        );
    }

    /** Hops up from below the board and lands with a squash. */
    enter(delay: number): void {
        const { scene, root } = this;

        root.y = scene.scale.height + this.drawing.height / 2;
        scene.tweens.add({
            targets: root,
            y: this.restY,
            delay,
            duration: 620,
            ease: 'Back.easeOut',
            onComplete: () => this.land(),
        });
    }

    /** A wrong animal shakes its head and stays. */
    wobble(): void {
        if (this.gone) {
            return;
        }

        this.scene.tweens.killTweensOf(this.inner);
        this.inner.setPosition(0, 0).setAngle(0).setScale(1);
        this.scene.tweens.add({
            targets: this.inner,
            x: [-18, 15, -11, 7, -3, 0],
            angle: [-5, 4, -3, 2, -1, 0],
            duration: 540,
            ease: 'Sine.easeInOut',
            // A miss as it landed cut the landing short.
            onComplete: () => this.hopNowAndThen(),
        });
    }

    /** The round is over: one hop up, then away off the bottom of the board. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        const { scene, root } = this;
        const away = root.x < scene.scale.width / 2 ? -1 : 1;

        scene.tweens.add({
            targets: root,
            x: root.x + away * 50,
            y: root.y - 110,
            delay,
            duration: 280,
            ease: 'Quad.easeOut',
            onComplete: () =>
                scene.tweens.add({
                    targets: root,
                    x: root.x + away * 140,
                    y: scene.scale.height + this.drawing.height,
                    duration: 600,
                    ease: 'Quad.easeIn',
                    onComplete: () => this.destroy(),
                }),
        });
    }

    /** Lands: squashed for a moment, its feet on the ground. */
    private land(): void {
        if (this.gone) {
            return;
        }

        const squash = 0.86;

        this.scene.tweens.add({
            targets: this.inner,
            scaleY: { from: squash, to: 1 },
            scaleX: { from: 1.08, to: 1 },
            y: { from: (this.drawing.height * (1 - squash)) / 2, to: 0 },
            duration: 280,
            ease: 'Back.easeOut',
            onComplete: () => this.hopNowAndThen(),
        });
    }

    private hopNowAndThen(): void {
        if (this.hopping) {
            return;
        }

        this.hopping = true;
        this.idle([
            {
                targets: this.root,
                y: this.restY - HOP,
                duration: 240,
                ease: 'Quad.easeOut',
                delay: Phaser.Math.Between(200, 1400),
                repeatDelay: Phaser.Math.Between(1300, 2400),
            },
        ]);
    }
}

export const animal: ItemStyle = {
    stagger: 120,
    layout: (count, band) => figureLayout(SHAPE, count, band),
    draw,
    create: (scene, item, options) => new Animal(scene, item, options),
};
