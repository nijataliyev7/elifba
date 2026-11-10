/**
 * The `fruit` item style (docs/architecture.md §2, "Figures"): a fruit
 * from the pack's figures hanging behind a plate with its item's text.
 * Fruits drop in from above, as from a tree, and bounce to rest; they sway
 * on their stalk while they wait, squash on a miss and fall away when their
 * round is over. A fruit hit correctly bursts.
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

/** How far a fruit sways either way, in degrees. */
const SWAY = 3.5;

const SHAPE: FigureShape = {
    compact: 1.18,
    roomy: 1.38,
    maxFigure: 1,
    overlap: 0.22,
    maxWidth: 350,
    margin: 130,
    fill: 0.84,
    // A sway lifts and drops the plate's corners a little.
    motion: { up: 8, down: 8 },
    // Fruit hang at two heights, as on a branch.
    restY: (index) => 1080 * 0.48 + (index % 2 === 0 ? 26 : -26),
};

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    return drawFigure(scene, text, look, SHAPE);
}

export class Fruit extends BoardItem {
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

    /** Drops from above the board and bounces to rest. */
    enter(delay: number): void {
        const { scene, root, inner } = this;

        root.y = -this.drawing.height;
        inner.setAngle(Phaser.Math.Between(-14, 14));
        scene.tweens.add({
            targets: root,
            y: this.restY,
            delay,
            duration: 950,
            ease: 'Bounce.easeOut',
        });
        scene.tweens.add({
            targets: inner,
            angle: -SWAY,
            delay,
            duration: 950,
            ease: 'Sine.easeOut',
            onComplete: () => this.sway(),
        });
    }

    /** A wrong fruit squashes and stretches, then sways again. */
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
            onComplete: () => this.sway(),
        });
    }

    /** The round is over: the fruit falls away off the bottom of the board. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.root,
            y: this.scene.scale.height + this.drawing.height,
            angle: Phaser.Math.Between(-24, 24),
            delay,
            duration: 760,
            ease: 'Quad.easeIn',
            onComplete: () => this.destroy(),
        });
    }

    /** Sways on its stalk, from one side to the other. */
    private sway(): void {
        if (this.gone) {
            return;
        }

        const swing = () =>
            this.idle([
                {
                    targets: this.inner,
                    angle: SWAY,
                    duration: Phaser.Math.Between(1500, 1900),
                    ease: 'Sine.easeInOut',
                },
            ]);

        if (Math.abs(this.inner.angle + SWAY) < 0.1) {
            swing();

            return;
        }

        this.scene.tweens.add({
            targets: this.inner,
            angle: -SWAY,
            duration: 500,
            ease: 'Sine.easeOut',
            onComplete: swing,
        });
    }
}

export const fruit: ItemStyle = {
    stagger: 120,
    layout: (count, band) => figureLayout(SHAPE, count, band),
    draw,
    create: (scene, item, options) => new Fruit(scene, item, options),
};
