/**
 * The `picture` item style (docs/architecture.md §2, ADR 012, ADR 014): a
 * white tile standing on a ledge of a pack colour, filled with the item's
 * picture as large as the tile allows, or with its text when it has none.
 * The board draws every round of the picture unit in it, whatever the
 * pack's own style (`stage.ts`), since a picture on a balloon or behind a
 * wagon would be hidden; a pack made for pictures may also name it (R6's
 * colours). A picture item shows no text on the board: the word is for the
 * adult, on the phone and in the prompt. `count.ts` draws the pictures it
 * asks about in the same tile (`draw()`), so the two look alike.
 *
 * The tile is drawn once per colour and size into a canvas texture
 * (`tileTexture()`): a soft shadow under it, the ledge — the tile's
 * thickness, in the colour, darker at its foot — a calm white face fading
 * to a tint of the colour at the bottom, and a thin rim in that tint. A
 * soft spot of shade grounds the picture, which Fluent's art draws without
 * a shadow of its own (ADR 014: "the tile draws one").
 *
 * Tiles pop in and their pictures follow with a bounce; while they wait
 * each picture breathes — rising a little and swelling while its shade
 * shrinks — each tile out of step with the others. A miss wobbles the tile
 * gently while the picture shakes its head; a correct hit makes the
 * picture hop as the effect takes the tile (burst: `pop()`, sparkle:
 * `lift()`), and when the round is over the tiles float away. A picture
 * level shows two to four, so they are large. The scene loads each picture
 * before it draws its tile (`figureKey()`, rasterised at `FIGURE_RASTER`).
 */
import Phaser from 'phaser';
import type { RoundItem } from '../contract';
import { BoardItem, hex, itemLabel, shade } from './item';
import type {
    ItemDrawing,
    ItemLook,
    ItemStyle,
    ItemViewOptions,
} from './types';

const MARGIN = 120;
/** The widest a tile is drawn: two tiles on the board are this big. */
const MAX_WIDTH = 500;
/** The share of its column a tile takes. */
const FILL = 0.86;
/** The longer side of the picture's drawing ÷ the tile's side. */
const SUBJECT = 0.76;
/** The ledge under the face ÷ the tile's side. */
const LEDGE = 0.05;
/** The corner radius ÷ the tile's side. */
const RADIUS = 0.16;
/** Room around the tile in its texture, for the shadow ÷ the side. */
const PAD = 0.14;

/** The parts of a drawn tile that move on their own. */
type Tile = {
    drawing: ItemDrawing;
    /** The picture, or the text when there is none. */
    content: Phaser.GameObjects.Image | Phaser.GameObjects.Text;
    /** The picture's shade on the face; null for text. */
    ground: Phaser.GameObjects.Image | null;
};

/** Where a picture's drawing lies in its square, as shares of its side. */
type Box = { x: number; y: number; width: number; height: number };

const WHOLE: Box = { x: 0, y: 0, width: 1, height: 1 };
/** By texture key: a picture's drawing does not move between games. */
const boxes = new Map<string, Box>();

/**
 * The box of a picture's drawing inside its square, read once from its
 * pixels: Fluent's pictures fill their square unevenly (a cloud or a
 * butterfly leaves room below it, a giraffe none), so the tile places
 * and sizes the drawing itself and puts its shade right under it. The
 * whole square when the pixels cannot be read.
 */
function subject(scene: Phaser.Scene, key: string): Box {
    const known = boxes.get(key);

    if (known !== undefined) {
        return known;
    }

    let box = WHOLE;

    try {
        const source = scene.textures.get(key).getSourceImage();
        const side = 96;
        const canvas = document.createElement('canvas');

        canvas.width = side;
        canvas.height = side;

        const context = canvas.getContext('2d', { willReadFrequently: true });

        if (
            context !== null &&
            (source instanceof HTMLImageElement ||
                source instanceof HTMLCanvasElement)
        ) {
            context.drawImage(source, 0, 0, side, side);

            const { data } = context.getImageData(0, 0, side, side);
            let left = side;
            let top = side;
            let right = -1;
            let bottom = -1;

            for (let y = 0; y < side; y++) {
                for (let x = 0; x < side; x++) {
                    if (data[(y * side + x) * 4 + 3] > 24) {
                        left = Math.min(left, x);
                        right = Math.max(right, x);
                        top = Math.min(top, y);
                        bottom = Math.max(bottom, y);
                    }
                }
            }

            if (right >= left && bottom >= top) {
                box = {
                    x: left / side,
                    y: top / side,
                    width: (right + 1 - left) / side,
                    height: (bottom + 1 - top) / side,
                };
            }
        }
    } catch {
        box = WHOLE;
    }

    boxes.set(key, box);

    return box;
}

/** The ledge's colour: the tile's, lightened when it is dark (the colours' grey), so no tile sits on a heavy base. */
function ledgeColour(colour: number): number {
    const { r, g, b } = Phaser.Display.Color.IntegerToRGB(colour);
    const light = (0.299 * r + 0.587 * g + 0.114 * b) / 255;

    return shade(colour, Phaser.Math.Clamp((0.55 - light) * 1.4, 0, 0.45));
}

function rgba(colour: number, alpha: number): string {
    const { r, g, b } = Phaser.Display.Color.IntegerToRGB(colour);

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

function roundedRect(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
): void {
    const r = Math.min(radius, width / 2, height / 2);

    context.beginPath();
    context.moveTo(x + r, y);
    context.arcTo(x + width, y, x + width, y + height, r);
    context.arcTo(x + width, y + height, x, y + height, r);
    context.arcTo(x, y + height, x, y, r);
    context.arcTo(x, y, x + width, y, r);
    context.closePath();
}

/** The tile without its picture, `side` px square, drawn once per colour and size. */
function tileTexture(
    scene: Phaser.Scene,
    colour: number,
    side: number,
): string {
    const size = Math.round(side);
    const key = `picture-tile-${hex(colour)}-${size}`;

    if (scene.textures.exists(key)) {
        return key;
    }

    const pad = Math.ceil(size * PAD);
    const texture = scene.textures.createCanvas(
        key,
        size + pad * 2,
        size + pad * 2,
    );

    if (texture === null) {
        return key;
    }

    const context = texture.context;
    const radius = size * RADIUS;
    const ledge = Math.round(size * LEDGE);
    const face = size - ledge;
    const rim = Math.max(3, size * 0.016);
    const base = ledgeColour(colour);

    // A soft shadow, in the colour's deep shade rather than grey.
    context.save();
    context.shadowColor = rgba(shade(colour, -0.6), 0.32);
    context.shadowBlur = size * 0.09;
    context.shadowOffsetY = size * 0.045;
    roundedRect(context, pad, pad, size, size, radius);
    context.fillStyle = hex(base);
    context.fill();
    context.restore();

    // The ledge: the tile's thickness, darker at its foot.
    const foot = context.createLinearGradient(0, pad + face, 0, pad + size);

    foot.addColorStop(0, hex(shade(base, -0.04)));
    foot.addColorStop(1, hex(shade(base, -0.22)));
    roundedRect(context, pad, pad, size, size, radius);
    context.fillStyle = foot;
    context.fill();

    // The face: white, a tint of the colour at the bottom.
    const fill = context.createLinearGradient(0, pad, 0, pad + face);

    fill.addColorStop(0, '#FFFFFF');
    fill.addColorStop(0.55, '#FFFFFF');
    fill.addColorStop(1, hex(shade(colour, 0.88)));
    roundedRect(context, pad, pad, size, face, radius);
    context.fillStyle = fill;
    context.fill();

    context.lineWidth = rim;
    context.strokeStyle = hex(shade(base, 0.45));
    roundedRect(
        context,
        pad + rim / 2,
        pad + rim / 2,
        size - rim,
        face - rim,
        radius - rim / 2,
    );
    context.stroke();
    texture.refresh();

    return key;
}

/** A soft oval of shade in the colour's deep shade, drawn once per colour. */
function groundTexture(scene: Phaser.Scene, colour: number): string {
    const key = `picture-ground-${hex(colour)}`;

    if (scene.textures.exists(key)) {
        return key;
    }

    const width = 200;
    const height = 60;
    const texture = scene.textures.createCanvas(key, width, height);

    if (texture === null) {
        return key;
    }

    const context = texture.context;
    const away = width * 4;

    // Only the blurred shadow of an oval drawn off the canvas lands on it.
    context.shadowColor = rgba(shade(colour, -0.55), 1);
    context.shadowBlur = 14;
    context.shadowOffsetX = away;
    context.fillStyle = '#000000';
    context.beginPath();
    context.ellipse(
        width / 2 - away,
        height / 2,
        width * 0.36,
        height * 0.22,
        0,
        0,
        Math.PI * 2,
    );
    context.fill();
    texture.refresh();

    return key;
}

function drawTile(scene: Phaser.Scene, text: string, look: ItemLook): Tile {
    const { width, colour } = look;
    const height = width;
    const ledge = Math.round(width * LEDGE);
    // The middle of the face, above the ledge.
    const middle = -ledge / 2;
    const tile = scene.add.image(0, 0, tileTexture(scene, colour, width));
    const pad = (tile.width - Math.round(width)) / 2;
    const parts: Phaser.GameObjects.GameObject[] = [tile];
    const figure = look.figure ?? null;
    let content: Tile['content'];
    let ground: Tile['ground'] = null;

    if (figure !== null && scene.textures.exists(figure)) {
        const box = subject(scene, figure);

        content = scene.add.image(0, 0, figure);

        const scale =
            (width * SUBJECT) /
            Math.max(box.width * content.width, box.height * content.height, 1);
        const drawn = {
            width: box.width * content.width * scale,
            height: box.height * content.height * scale,
        };
        // The drawing's middle a little above the face's, for its shade.
        const y = middle - width * 0.02;

        content
            .setScale(scale)
            .setPosition(
                (0.5 - box.x - box.width / 2) * content.width * scale,
                y + (0.5 - box.y - box.height / 2) * content.height * scale,
            );
        ground = scene.add.image(
            0,
            y + drawn.height / 2 + width * 0.01,
            groundTexture(scene, colour),
        );
        ground
            .setDisplaySize(
                Math.min(drawn.width * 0.95, width * 0.8),
                width * 0.13,
            )
            .setAlpha(0.22);
        parts.push(ground, content);
    } else {
        content = itemLabel(scene, text, width * 0.36, 0xffffff, width * 0.84);
        content.setY(middle);
        parts.push(content);
    }

    return {
        drawing: {
            root: scene.add.container(0, 0, parts),
            body: tile,
            // The image's own frame, padded for the shadow: the touch takes the tile.
            hitArea: {
                hitArea: new Phaser.Geom.Rectangle(pad, pad, width, height),
                hitAreaCallback: (
                    area: Phaser.Geom.Rectangle,
                    px: number,
                    py: number,
                ) => Phaser.Geom.Rectangle.Contains(area, px, py),
                useHandCursor: true,
            },
            width,
            height,
        },
        content,
        ground,
    };
}

function draw(scene: Phaser.Scene, text: string, look: ItemLook): ItemDrawing {
    return drawTile(scene, text, look).drawing;
}

export class PictureTile extends BoardItem {
    private readonly content: Tile['content'];
    private readonly ground: Tile['ground'];
    private readonly contentX: number;
    private readonly contentY: number;
    private readonly contentScale: number;
    private shake: Phaser.Tweens.Tween | null = null;

    constructor(
        scene: Phaser.Scene,
        item: RoundItem,
        options: ItemViewOptions,
    ) {
        const { x, y, width, colour, figure } = options;
        const tile = drawTile(scene, item.text, { width, colour, figure });

        super(scene, item.id, colour, tile.drawing, x, y);
        this.content = tile.content;
        this.ground = tile.ground;
        this.contentX = tile.content.x;
        this.contentY = tile.content.y;
        this.contentScale = tile.content.scaleX;
    }

    /** Pops in where it rests; the picture follows with a bounce. */
    enter(delay: number): void {
        const { scene, root, content } = this;

        root.setScale(0).setAlpha(0);
        content.setScale(this.contentScale * 0.5);
        scene.tweens.add({
            targets: root,
            scale: 1,
            alpha: 1,
            delay,
            duration: 480,
            ease: 'Back.easeOut',
        });
        scene.tweens.add({
            targets: content,
            scale: this.contentScale,
            delay: delay + 140,
            duration: 560,
            ease: 'Back.easeOut',
            easeParams: [2.4],
            onComplete: () => this.breathe(),
        });
    }

    /** A wrong tile wobbles gently and its picture shakes its head. */
    wobble(): void {
        if (this.gone) {
            return;
        }

        const { scene, inner, content } = this;
        const width = this.drawing.width;

        scene.tweens.killTweensOf(inner);
        inner.setAngle(0).setScale(1);
        scene.tweens.add({
            targets: inner,
            angle: [-4, 3.5, -2.5, 1.2, 0],
            duration: 560,
            ease: 'Sine.easeInOut',
        });
        this.shake?.stop();
        this.shake = scene.tweens.add({
            targets: content,
            x: [-0.035, 0.03, -0.02, 0.01, 0].map(
                (share) => this.contentX + share * width,
            ),
            duration: 560,
            ease: 'Sine.easeInOut',
        });
    }

    /** Burst: the picture hops up, the tile swells, and the burst takes it. */
    pop(): void {
        if (!this.finish()) {
            return;
        }

        this.hop(90);
        this.scene.tweens.add({
            targets: this.inner,
            scaleX: 1.1,
            scaleY: 1.16,
            duration: 90,
            ease: 'Quad.easeOut',
            onComplete: () => this.destroy(),
        });
    }

    /** Sparkle: the picture hops for joy, then the tile lifts and fades away whole. */
    lift(): void {
        if (!this.finish()) {
            return;
        }

        const { scene, inner, root } = this;

        this.hop(170);
        scene.tweens.add({
            targets: inner,
            angle: 0,
            scale: 1.1,
            duration: 260,
            ease: 'Back.easeOut',
        });
        scene.tweens.add({
            targets: root,
            y: root.y - 150,
            alpha: 0,
            delay: 180,
            duration: 820,
            ease: 'Sine.easeIn',
            onComplete: () => this.destroy(),
        });
    }

    /** The round is over: the tile floats up and fades. */
    leave(delay: number): void {
        if (!this.finish()) {
            return;
        }

        this.scene.tweens.add({
            targets: this.root,
            y: this.root.y - 140,
            alpha: 0,
            scale: 0.88,
            delay,
            duration: 650,
            ease: 'Sine.easeIn',
            onComplete: () => this.destroy(),
        });
    }

    destroy(): void {
        this.scene.tweens.killTweensOf(
            this.ground === null ? [this.content] : [this.content, this.ground],
        );
        super.destroy();
    }

    /** The picture jumps up and lands, `up` ms each way. */
    private hop(up: number): void {
        const { scene, content, ground } = this;

        scene.tweens.killTweensOf(content);
        scene.tweens.add({
            targets: content,
            y: this.contentY - this.drawing.height * 0.12,
            scale: this.contentScale * 1.06,
            duration: up,
            ease: 'Quad.easeOut',
            yoyo: true,
        });

        if (ground !== null) {
            scene.tweens.killTweensOf(ground);
            scene.tweens.add({
                targets: ground,
                scaleX: ground.scaleX * 0.7,
                alpha: 0.12,
                duration: up,
                ease: 'Quad.easeOut',
                yoyo: true,
            });
        }
    }

    /** Rises a little and swells while its shade shrinks, out of step with the other tiles. */
    private breathe(): void {
        const duration = Phaser.Math.Between(1500, 2100);
        const delay = Phaser.Math.Between(0, duration);
        const breath = {
            duration,
            delay,
            ease: 'Sine.easeInOut',
        };
        const tweens: Phaser.Types.Tweens.TweenBuilderConfig[] = [
            {
                targets: this.content,
                y: this.contentY - this.drawing.height * 0.014,
                scale: this.contentScale * 1.035,
                ...breath,
            },
        ];

        if (this.ground !== null) {
            tweens.push({
                targets: this.ground,
                scaleX: this.ground.scaleX * 0.92,
                alpha: 0.17,
                ...breath,
            });
        }

        this.idle(tweens);
    }
}

export const picture: ItemStyle = {
    stagger: 120,
    layout(count, band) {
        const slot = (1920 - MARGIN * 2) / Math.max(count, 1);
        // In a band the shadow stays inside it.
        const fit =
            band === undefined
                ? Infinity
                : (band.bottom - band.top - 30) / 1.04;
        const width = Math.min(MAX_WIDTH, slot * FILL, fit);

        return {
            width,
            height: width,
            spots: Array.from({ length: count }, (_, index) => ({
                x: MARGIN + slot * (index + 0.5),
                y:
                    band === undefined
                        ? 1080 * 0.5
                        : (band.top + band.bottom) / 2 + 3,
            })),
        };
    },
    draw,
    create: (scene, item, options) => new PictureTile(scene, item, options),
};
