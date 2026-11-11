/**
 * The `build_word` scene, «Sözü yığ» (docs/architecture.md §2): the
 * word's picture is the question, big at the top of the board so the back
 * of the class can see it; the word is built from its syllables in the
 * slots below. The word's text is never on the board until the round is
 * solved: the picture has no caption, and only the slots fill in.
 */
import Phaser from 'phaser';
import type { Round } from '../contract';
import { css, paintPlate, texture } from '../styles/fluent';
import type { SceneOptions } from './board-scene';
import { BuildScene } from './build';
import { BUILD_LAYOUTS, PICTURE_FRAME as FRAME } from './layouts';

/** The picture is rasterised at this size and fitted into its frame. */
const RASTER = 640;
const PICTURE = 320;

export class BuildWordScene extends BuildScene {
    protected readonly layout = BUILD_LAYOUTS.build_word;

    private picture: Phaser.GameObjects.Container | null = null;

    constructor(options: SceneOptions) {
        super('build_word', options);
    }

    /**
     * The question's frame, painted once per pack colour in ADR 014's
     * Fluent manner: a white face set into a plate in the colour, lit from
     * the top left, with a soft shadow in its deep shade — no outline.
     */
    private frameTexture(): string {
        const { width, height } = FRAME;
        const colour = this.palette[0];
        const room = 24;

        return texture(
            this,
            `build-word-frame-${css(colour)}-${width}x${height}`,
            width + room * 2,
            height + room * 2,
            (context) =>
                paintPlate(
                    context,
                    { x: room, y: room, width, height },
                    {
                        colour,
                        radius: 36,
                        inset: { side: 8, top: 8, bottom: 8 },
                        face: 0xffffff,
                        faceRadius: 32,
                        shadow: { blur: 22, drop: 10 },
                    },
                ),
        );
    }

    protected ask(round: Round, wait: number): void {
        this.dropPicture();

        const card = this.add.image(0, 0, this.frameTexture());

        const picture = this.add.container(FRAME.x, FRAME.y, [card]);

        picture.setScale(0.6).setAlpha(0);
        this.tweens.add({
            targets: picture,
            scale: 1,
            alpha: 1,
            delay: 150 + wait,
            duration: 450,
            ease: 'Back.easeOut',
        });
        this.picture = picture;

        const url = round.prompt.image;

        if (url === null) {
            return;
        }

        const key = `picture:${url}`;
        const show = () => {
            if (!picture.active || !this.textures.exists(key)) {
                return;
            }

            const image = this.add.image(0, 0, key);

            image.setScale(PICTURE / Math.max(image.width, image.height));
            picture.add(image);
        };

        if (this.textures.exists(key)) {
            show();

            return;
        }

        // Pictures are SVGs (the pack's emoji set or its own): rasterised at a
        // size of their own, as backdrops are, so WebGL draws them sharp.
        this.load.svg(key, url, { width: RASTER, height: RASTER });
        this.load.once(
            `${Phaser.Loader.Events.FILE_KEY_COMPLETE}svg-${key}`,
            show,
        );
        this.load.start();
    }

    private dropPicture(): void {
        const old = this.picture;

        if (old === null) {
            return;
        }

        this.picture = null;
        this.tweens.add({
            targets: old,
            y: old.y - 80,
            alpha: 0,
            duration: 450,
            ease: 'Cubic.easeIn',
            onComplete: () => old.destroy(),
        });
    }
}
