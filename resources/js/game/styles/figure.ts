/**
 * What the `animal` and `fruit` item styles share (docs/architecture.md §2,
 * "Figures"): a picture from the pack's `style.figures` standing behind a
 * rounded plate with the item's text, and where such items rest. The plate
 * covers the picture's lower part, so the picture never covers a letter.
 *
 * The layout decides the item's box and the item fills it: on the free
 * board (find, pop) the item is tall and the figure big and nearly whole;
 * in a build band, where height is short, it is compact (its tiles, and the
 * copies that fill its slots). Readability first: the text is `card`'s size
 * for the item's width (0.46), which beats `bubble`'s (0.42), and a figure
 * item is never narrower than a card or a bubble in the same layout — on
 * the free board it is wider, since the columns have room, and in a band
 * its compact height ÷ width and its motion leave it as wide. The text sits on the middle of its glyphs
 * (Andika's capitals with a dot or breve over Ş or Ç reach 1.21 em) and
 * shrinks only to fit a long word (`böyürtkən`, `dəvəquşu`) on the plate.
 *
 * The scene rasterises every figure once (`FIGURE_RASTER`, the largest an
 * item draws one), so `draw()` can also make the build rounds' copies.
 */
import Phaser from 'phaser';
import { itemLabel, shade } from './item';
import type { Band, ItemDrawing, ItemLayout, ItemLook } from './types';

/** The plate's height ÷ the item's width. */
const PLATE = 0.6;
/** Text size ÷ the item's width: `card`'s. */
export const FIGURE_TEXT = 0.46;
/** The widest the text may be ÷ the item's width. */
const ROOM = 0.86;
/** The plate's coloured rim ÷ the item's width. */
const RIM = 0.022;
/** Space kept free at both ends of a band. */
const BAND_GAP = 6;

/** How one style of figure item is shaped and laid out. */
export type FigureShape = {
    /** height ÷ width in a build band, where height is short. */
    compact: number;
    /** height ÷ width on the free board (find, pop). */
    roomy: number;
    /** The widest a figure may be ÷ the item's width, so it keeps to its column. */
    maxFigure: number;
    /** How much of the figure's height the plate hides. */
    overlap: number;
    /** The widest an item is drawn. */
    maxWidth: number;
    /** Free space at the board's left and right edges. */
    margin: number;
    /** The share of its column an item takes. */
    fill: number;
    /** How far the idle motion takes an item above and below its rest. */
    motion: { up: number; down: number };
    /** Where item `index` rests across the free board (find, pop). */
    restY: (index: number) => number;
};

/** The size figures are rasterised at: the largest an item draws them. */
export const FIGURE_RASTER = 416;

export function figureLayout(
    shape: FigureShape,
    count: number,
    band?: Band,
): ItemLayout {
    const aspect = band === undefined ? shape.roomy : shape.compact;
    const column = (1920 - shape.margin * 2) / Math.max(count, 1);
    // In a band the idle motion stays inside it.
    const fit =
        band === undefined
            ? Infinity
            : (band.bottom -
                  band.top -
                  shape.motion.up -
                  shape.motion.down -
                  BAND_GAP * 2) /
              aspect;
    const width = Math.min(shape.maxWidth, column * shape.fill, fit);

    return {
        width,
        height: Math.round(width * aspect),
        spots: Array.from({ length: count }, (_, index) => ({
            x: shape.margin + column * (index + 0.5),
            y:
                band === undefined
                    ? shape.restY(index)
                    : (band.top + band.bottom) / 2 +
                      (shape.motion.up - shape.motion.down) / 2,
        })),
    };
}

/** A figure item, still: the picture, the plate in a palette colour and the text. */
export function drawFigure(
    scene: Phaser.Scene,
    text: string,
    look: ItemLook,
    shape: FigureShape,
): ItemDrawing {
    const { width, colour } = look;
    const height = Math.round(look.height ?? width * shape.compact);
    const plate = width * PLATE;
    const top = -height / 2;
    const plateTop = height / 2 - plate;
    // As big as the room above the plate allows, its feet behind the plate.
    const side = Math.min(
        width * shape.maxFigure,
        (plateTop - top) / (1 - shape.overlap),
    );
    const radius = plate * 0.28;
    const rim = Math.max(4, width * RIM);
    const face = shade(colour, 0.86);
    const parts: Phaser.GameObjects.GameObject[] = [];

    if (
        look.figure !== undefined &&
        look.figure !== null &&
        scene.textures.exists(look.figure)
    ) {
        parts.push(
            scene.add
                .image(0, plateTop + side * (shape.overlap - 0.5), look.figure)
                .setDisplaySize(side, side),
        );
    }

    const sign = scene.add.graphics();

    sign.fillStyle(0x1f2937, 0.16);
    sign.fillRoundedRect(-width / 2 + 6, plateTop + 10, width, plate, radius);
    sign.fillStyle(face, 1);
    sign.fillRoundedRect(-width / 2, plateTop, width, plate, radius);
    sign.lineStyle(rim, colour, 1);
    sign.strokeRoundedRect(
        -width / 2 + rim / 2,
        plateTop + rim / 2,
        width - rim,
        plate - rim,
        radius - rim / 2,
    );
    sign.lineStyle(3, shade(colour, -0.3), 1);
    sign.strokeRoundedRect(-width / 2, plateTop, width, plate, radius);
    parts.push(sign);

    const label = itemLabel(
        scene,
        text,
        width * FIGURE_TEXT,
        face,
        width * ROOM,
    );

    seat(label, text, plateTop + plate / 2, plate - rim * 2);
    parts.push(label);

    return {
        root: scene.add.container(0, 0, parts),
        body: sign,
        // The whole item, picture and plate, takes a touch.
        hitArea: {
            hitArea: new Phaser.Geom.Rectangle(-width / 2, top, width, height),
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

/**
 * Puts the middle of the label's glyphs (not of its line box, which holds
 * room for marks the word may not have) at `y`, shrunk if the glyphs are
 * taller than `room`.
 */
function seat(
    label: Phaser.GameObjects.Text,
    text: string,
    y: number,
    room: number,
): void {
    label.style.syncFont(label.canvas, label.context);

    const glyphs = label.context.measureText(text);
    const ascent = glyphs.actualBoundingBoxAscent;
    const descent = glyphs.actualBoundingBoxDescent;
    const baseline =
        (label.padding.top ?? 0) +
        label.getTextMetrics().ascent -
        label.height / 2;
    const scale = Math.min(label.scaleX, room / (ascent + descent));

    label.setScale(scale);
    label.setY(y - scale * (baseline + (descent - ascent) / 2));
}
