/**
 * The `count` scene, «Say» (docs/architecture.md §2 "The count builder",
 * ADR 012): «Neçə alma var?». The question band at the top holds the
 * round's picture `prompt.count` times, in rows of five as a ten-frame
 * (`countPictures()`); the items — numbers — stand in the band below in
 * the pack's item style, as a build round's tiles do. The number is never
 * written over the pictures: the child counts them and taps the digit.
 * The pictures come in one by one, so the adult can count along; when the
 * round is solved they leave with the items. Each picture stands in the
 * `picture` style's tile (`styles/picture.ts`), in the pack's first colour.
 */
import Phaser from 'phaser';
import type { Round, RoundProgress } from '../contract';
import { figureKey } from '../figures';
import { ITEM_STYLES } from '../styles/registry';
import type { ItemLayout, ItemStyle } from '../styles/types';
import type { SceneOptions } from './board-scene';
import { FindScene } from './find';
import { COUNT_LAYOUT, countPictures } from './layouts';

/** ms between one picture's entrance and the next. */
const PICTURE_STAGGER = 110;

export class CountScene extends FindScene {
    private question: Phaser.GameObjects.Container | null = null;

    constructor(options: SceneOptions) {
        super(options, 'count');
    }

    protected scenery(): void {
        this.options.style.scenery?.(this, COUNT_LAYOUT.items);
    }

    protected itemLayout(style: ItemStyle, count: number): ItemLayout {
        return style.layout(count, COUNT_LAYOUT.items);
    }

    protected ask(round: Round, progress: RoundProgress): void {
        this.dropQuestion(0);

        // A round solved before a reload shows nothing more to count.
        if (progress.solved) {
            return;
        }

        const url = round.prompt.image;
        const question = this.add.container(0, 0);

        this.question = question;

        if (url === null) {
            return;
        }

        this.loadPictures([url], () => {
            if (
                this.question !== question ||
                !question.active ||
                !this.textures.exists(figureKey(url))
            ) {
                return;
            }

            this.drawPictures(
                question,
                figureKey(url),
                round.prompt.count ?? 0,
            );
        });
    }

    protected answered(): void {
        this.dropQuestion(420);
    }

    private drawPictures(
        question: Phaser.GameObjects.Container,
        texture: string,
        count: number,
    ): void {
        const { size, spots } = countPictures(count);

        spots.forEach((spot, index) => {
            // The picture style's tile, so a counting round looks like a picture round.
            const picture = ITEM_STYLES.picture.draw(this, '', {
                width: size,
                colour: this.palette[0],
                figure: texture,
            }).root;

            picture.setPosition(spot.x, spot.y).setScale(0).setAlpha(0);
            question.add(picture);
            this.tweens.add({
                targets: picture,
                scale: 1,
                alpha: 1,
                delay: 150 + index * PICTURE_STAGGER,
                duration: 380,
                ease: 'Back.easeOut',
            });
        });
    }

    private dropQuestion(delay: number): void {
        const old = this.question;

        if (old === null) {
            return;
        }

        this.question = null;
        this.tweens.add({
            targets: old,
            y: -80,
            alpha: 0,
            delay,
            duration: 450,
            ease: 'Cubic.easeIn',
            onComplete: () => old.destroy(),
        });
    }
}
