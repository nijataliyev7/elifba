/**
 * The `card` item style (docs/architecture.md §2): a playing card with a
 * light face in a pack colour, a coloured frame and band, and its item's
 * text. Cards are dealt from above and turn face up, hover while they
 * wait, shake on a miss and slide down out of sight when their round is
 * over. The national symbols and cities use cards with `sparkle`, so a
 * card hit there lifts away whole (§8).
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import { BoardItem, itemLabel, shade } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const MARGIN = 140;

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    const { width, colour } = look;
    const height = Math.round(width * 1.3);
    const radius = Math.round(width * 0.1);
    const face = shade(colour, 0.84);
    const left = -width / 2;
    const top = -height / 2;
    const card = scene.add.graphics();

    // Shadow, face, frame, then the band at the top and bottom.
    card.fillStyle(0x1f2937, 0.14);
    card.fillRoundedRect(left + 8, top + 12, width, height, radius);
    card.fillStyle(face, 1);
    card.fillRoundedRect(left, top, width, height, radius);
    card.fillStyle(colour, 1);
    card.fillRoundedRect(left, top, width, height * 0.12, {
        tl: radius,
        tr: radius,
        bl: 0,
        br: 0,
    });
    card.fillRoundedRect(left, -top - height * 0.12, width, height * 0.12, {
        tl: 0,
        tr: 0,
        bl: radius,
        br: radius,
    });
    card.lineStyle(6, shade(colour, -0.2), 1);
    card.strokeRoundedRect(left, top, width, height, radius);

    // Small diamonds on the bands, as a card's corners have.
    card.fillStyle(face, 0.9);

    for (const y of [top + height * 0.06, -top - height * 0.06]) {
        const d = height * 0.03;

        card.fillTriangle(-d, y, 0, y - d, d, y);
        card.fillTriangle(-d, y, 0, y + d, d, y);
    }

    const label = itemLabel(scene, text, width * 0.46, face, width * 0.82);

    return {
        root: scene.add.container(0, 0, [card, label]),
        body: card,
        // A graphics object's hit area is in its own coordinates, centred here.
        hitArea: {
            hitArea: new Phaser.Geom.Rectangle(left, top, width, height),
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
            band === undefined ? Infinity : (band.bottom - band.top - 30) / 1.3;
        const width = Math.min(300, slot * 0.78, fit);

        return {
            width,
            height: Math.round(width * 1.3),
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
