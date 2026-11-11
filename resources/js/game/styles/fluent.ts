/**
 * The Fluent manner of ADR 014's own-art style guide, for the board's
 * item and slot styles (docs/architecture.md §2, "Item styles and
 * effects"), so the pieces that carry letters look like the Fluent
 * pictures beside them: no outline — an edge is where a lighter surface
 * meets a darker one — light from the top left, a surface filled with a
 * radial gradient light → base → shade, a shade from the bottom right, one
 * soft highlight, and shade that turns towards red and violet, never grey.
 *
 * A look is painted once per colour and size into a canvas texture
 * (`texture()`), never per frame, as `picture.ts` paints its tile: the
 * board must stay smooth on an old school laptop. Sizes are rounded up to
 * `STEP` px (`step()`), and the style scales the image down by the rest,
 * so a round with a new number of tiles does not paint a new texture for
 * every pixel of width.
 */
import type Phaser from 'phaser';

/** Texture sizes are rounded up to this many px. */
export const STEP = 8;

/** A colour's light, base, shade and deep shade (for shadows under a surface). */
export type Tones = {
    light: number;
    base: number;
    shade: number;
    deep: number;
};

/** A material's light, base and shade, as ADR 014's palette gives them. */
export type Material = { light: number; base: number; shade: number };

/** ADR 014's materials for the parts that are not in a pack colour. */
export const TYRE: Material = {
    light: 0x6a6571,
    base: 0x554a63,
    shade: 0x33293d,
};
export const METAL: Material = {
    light: 0xdad9db,
    base: 0xc2bdc9,
    shade: 0x988ea1,
};
export const WOOD: Material = {
    light: 0xe8a66f,
    base: 0xc48462,
    shade: 0x5f4341,
};
export const BRASS: Material = {
    light: 0xfff246,
    base: 0xf8ca4d,
    shade: 0xc79738,
};

export const SKY: Material = {
    light: 0x8befff,
    base: 0x6cceff,
    shade: 0x6e99ff,
};
/** White cloth, paper and cloud; its edge is tinted violet (`#D0C9EE`). */
export const CLOUD: Material = {
    light: 0xfcfbfe,
    base: 0xe8e8e9,
    shade: 0xd0c9ee,
};

/** A surface's bounding box: the light sits at 30 % across and 25 % down it, the shade at its bottom right. */
export type Box = { x: number; y: number; width: number; height: number };

type Hsl = { h: number; s: number; l: number };

const WARM_WHITE = 0xfffdf6;
/** Where shade turns to (red violet) and light to (yellow), in degrees of hue. */
const SHADE_HUE = 330;
const LIGHT_HUE = 55;

function rgbOf(colour: number): [number, number, number] {
    return [(colour >> 16) & 0xff, (colour >> 8) & 0xff, colour & 0xff];
}

function hsl(colour: number): Hsl {
    const [r, g, b] = rgbOf(colour).map((channel) => channel / 255);
    const max = Math.max(r, g, b);
    const min = Math.min(r, g, b);
    const l = (max + min) / 2;

    if (max === min) {
        return { h: 0, s: 0, l };
    }

    const d = max - min;
    const s = l > 0.5 ? d / (2 - max - min) : d / (max + min);
    const h =
        max === r
            ? (g - b) / d + (g < b ? 6 : 0)
            : max === g
              ? (b - r) / d + 2
              : (r - g) / d + 4;

    return { h: h * 60, s, l };
}

function fromHsl({ h, s, l }: Hsl): number {
    const c = (1 - Math.abs(2 * l - 1)) * s;
    const x = c * (1 - Math.abs(((h / 60) % 2) - 1));
    const m = l - c / 2;
    const [r, g, b] =
        h < 60
            ? [c, x, 0]
            : h < 120
              ? [x, c, 0]
              : h < 180
                ? [0, c, x]
                : h < 240
                  ? [0, x, c]
                  : h < 300
                    ? [x, 0, c]
                    : [c, 0, x];
    const channel = (value: number) =>
        Math.max(0, Math.min(255, Math.round((value + m) * 255)));

    return (channel(r) << 16) | (channel(g) << 8) | channel(b);
}

/** `hue` turned by at most `step` degrees towards `target`, the short way round. */
function turn(hue: number, target: number, step: number): number {
    const delta = ((target - hue + 540) % 360) - 180;
    const turned = hue + Math.sign(delta) * Math.min(Math.abs(delta), step);

    return (turned + 360) % 360;
}

/** `colour` mixed `amount` (0–1) of the way to `to`. */
export function mix(colour: number, to: number, amount: number): number {
    const a = rgbOf(colour);
    const b = rgbOf(to);
    const [r, g, bl] = a.map((channel, index) =>
        Math.round(channel + (b[index] - channel) * amount),
    );

    return (r << 16) | (g << 8) | bl;
}

/**
 * A colour's tones in Fluent's manner: light towards yellow and white,
 * shade darker and more saturated, turned towards red and violet, never
 * grey. `depth` (0–1) softens them for a pale surface (a card's face).
 */
export function tones(colour: number, depth: number = 1): Tones {
    const { h, s, l } = hsl(colour);
    // A grey has no hue of its own: its shade leans violet, as Fluent's stone does.
    const grey = s < 0.08;
    // Yellow turns only a little, as Fluent's gold does, or it would read as orange.
    const yellow = h >= 35 && h <= 75;
    const shadeHue = grey ? 275 : turn(h, SHADE_HUE, (yellow ? 5 : 14) * depth);

    return {
        light: fromHsl({
            h: grey ? h : turn(h, LIGHT_HUE, 8 * depth),
            s,
            l: l + (1 - l) * 0.4 * depth,
        }),
        base: colour,
        shade: fromHsl({
            h: shadeHue,
            s: Math.min(1, grey ? 0.12 : s * 1.06 + 0.06 * depth),
            l: l * (1 - 0.32 * depth),
        }),
        deep: fromHsl({
            h: grey ? 275 : turn(h, SHADE_HUE, yellow ? 12 : 24),
            s: Math.min(1, grey ? 0.2 : s + 0.08),
            l: l * 0.36,
        }),
    };
}

export function css(colour: number): string {
    return `#${colour.toString(16).padStart(6, '0')}`;
}

export function rgba(colour: number, alpha: number): string {
    const [r, g, b] = rgbOf(colour);

    return `rgba(${r}, ${g}, ${b}, ${alpha})`;
}

/** `size` rounded up to the texture step. */
export function step(size: number): number {
    return Math.max(STEP, Math.ceil(size / STEP) * STEP);
}

/**
 * The texture `key`, painted once by `paint` on a `width` × `height`
 * canvas; a later call with the same key reuses it.
 */
export function texture(
    scene: Phaser.Scene,
    key: string,
    width: number,
    height: number,
    paint: (context: CanvasRenderingContext2D) => void,
): string {
    if (scene.textures.exists(key)) {
        return key;
    }

    const canvas = scene.textures.createCanvas(
        key,
        Math.ceil(width),
        Math.ceil(height),
    );

    if (canvas === null) {
        return key;
    }

    paint(canvas.context);
    canvas.refresh();

    return key;
}

/** A rounded rectangle's path; `fresh` false adds it to the current path. */
export function roundedRect(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    width: number,
    height: number,
    radius: number,
    fresh: boolean = true,
): void {
    const r = Math.max(0, Math.min(radius, width / 2, height / 2));

    if (fresh) {
        context.beginPath();
    }

    context.moveTo(x + r, y);
    context.arcTo(x + width, y, x + width, y + height, r);
    context.arcTo(x + width, y + height, x, y + height, r);
    context.arcTo(x, y + height, x, y, r);
    context.arcTo(x, y, x + width, y, r);
    context.closePath();
}

/** An ellipse's path, centred on (x, y). */
export function ellipse(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    rx: number,
    ry: number,
): void {
    context.beginPath();
    context.ellipse(x, y, rx, ry, 0, 0, Math.PI * 2);
    context.closePath();
}

/**
 * Fills the path `shape` draws as one Fluent surface in `colour` (or a
 * material's own tones): the base,
 * a radial gradient from the top left of `box` (light → base → shade), and
 * the shade from its bottom right. Gradients are laid out in the box's own
 * units, as SVG's bounding-box gradients are, so a long wagon is lit like a
 * round balloon.
 */
export function surface(
    context: CanvasRenderingContext2D,
    shape: () => void,
    box: Box,
    colour: number | Material,
    options: { depth?: number; shade?: number } = {},
): void {
    const t =
        typeof colour === 'number' ? tones(colour, options.depth ?? 1) : colour;
    const inBox = (fill: (context: CanvasRenderingContext2D) => void) => {
        shape();
        context.save();
        context.translate(box.x, box.y);
        context.scale(Math.max(box.width, 1), Math.max(box.height, 1));
        fill(context);
        context.fill();
        context.restore();
    };

    inBox((c) => {
        const base = c.createRadialGradient(0.3, 0.25, 0, 0.3, 0.25, 0.85);

        base.addColorStop(0, css(t.light));
        base.addColorStop(0.42, css(t.base));
        base.addColorStop(1, css(t.shade));
        c.fillStyle = base;
    });
    inBox((c) => {
        const shade = c.createRadialGradient(1, 1, 0, 1, 1, 0.9);

        shade.addColorStop(0, rgba(t.shade, options.shade ?? 0.6));
        shade.addColorStop(1, rgba(t.shade, 0));
        c.fillStyle = shade;
    });
}

/**
 * One soft highlight: an ellipse centred on (x, y), turned by `angle`
 * radians, in a near-white tint of `colour`, fading out at its edge (the
 * guide's blurred highlight, without a canvas filter, which some browsers
 * lack).
 */
export function highlight(
    context: CanvasRenderingContext2D,
    x: number,
    y: number,
    rx: number,
    ry: number,
    angle: number,
    colour: number,
    alpha: number = 0.75,
): void {
    const tint = mix(colour, WARM_WHITE, 0.82);

    context.save();
    context.translate(x, y);
    context.rotate(angle);
    context.scale(Math.max(rx, 0.5), Math.max(ry, 0.5));

    const soft = context.createRadialGradient(0, 0, 0, 0, 0, 1);

    soft.addColorStop(0, rgba(tint, alpha));
    soft.addColorStop(0.5, rgba(tint, alpha * 0.8));
    soft.addColorStop(1, rgba(tint, 0));
    context.fillStyle = soft;
    context.beginPath();
    context.arc(0, 0, 1, 0, Math.PI * 2);
    context.fill();
    context.restore();
}

/**
 * A soft shadow under the path `shape` draws, in `colour`'s deep shade
 * (never grey), `blur` px wide and `drop` px down. The shape itself is
 * filled too, so paint the surface over it.
 */
export function shadow(
    context: CanvasRenderingContext2D,
    shape: () => void,
    colour: number,
    blur: number,
    drop: number,
    alpha: number = 0.3,
): void {
    context.save();
    context.shadowColor = rgba(tones(colour).deep, alpha);
    context.shadowBlur = blur;
    context.shadowOffsetY = drop;
    shape();
    context.fillStyle = css(colour);
    context.fill();
    context.restore();
}

/**
 * A soft glow around a rounded rectangle `width` × `height` in `colour`,
 * `spread` px beyond its edges: the next slot to fill, pulsing behind it.
 * Only the blurred shadow of a shape drawn off the canvas lands on it.
 */
export function glowTexture(
    scene: Phaser.Scene,
    colour: number,
    width: number,
    height: number,
    radius: number,
    spread: number,
): string {
    const w = step(width);
    const h = step(height);
    const key = `fluent-glow-${css(colour)}-${w}x${h}-${Math.round(radius)}-${Math.round(spread)}`;
    const pad = Math.ceil(spread * 2);

    return texture(scene, key, w + pad * 2, h + pad * 2, (context) => {
        const away = (w + pad * 2) * 2;

        context.shadowColor = rgba(colour, 1);
        context.shadowBlur = spread;
        context.shadowOffsetX = away;
        roundedRect(
            context,
            pad - spread * 0.35 - away,
            pad - spread * 0.35,
            w + spread * 0.7,
            h + spread * 0.7,
            radius + spread * 0.35,
        );
        context.fillStyle = '#000000';
        context.fill();
    });
}

/** How a plate is laid out: a surface in a colour, and a light face set into it. */
export type PlateLook = {
    /** The plate's colour: its border and bands. */
    colour: number;
    radius: number;
    /** How far the face is set in from each edge. */
    inset: { side: number; top: number; bottom: number };
    /** The face's colour, which its text is chosen for. */
    face: number;
    /** The face's corner radius; by default what is left of the plate's inside the inset. */
    faceRadius?: number;
    /** A soft shadow under the plate, `blur` px wide and `drop` px down; none when missing. */
    shadow?: { blur: number; drop: number };
};

/**
 * Paints a plate filling `box`: a Fluent surface in its colour — the
 * border and bands where the face does not cover it, no outline — and a
 * light face set into it, pale where the light falls, with the border's
 * shade falling on its top edge. The card, the figures' plate, a filled
 * frame and the solved unit's plate share it.
 */
export function paintPlate(
    context: CanvasRenderingContext2D,
    box: Box,
    look: PlateLook,
): void {
    const { colour, radius, inset } = look;
    const plate = () =>
        roundedRect(context, box.x, box.y, box.width, box.height, radius);

    if (look.shadow !== undefined) {
        shadow(
            context,
            plate,
            colour,
            look.shadow.blur,
            look.shadow.drop,
            0.34,
        );
    }

    surface(context, plate, box, colour);

    const face = {
        x: box.x + inset.side,
        y: box.y + inset.top,
        width: box.width - inset.side * 2,
        height: box.height - inset.top - inset.bottom,
    };
    const faceShape = () =>
        roundedRect(
            context,
            face.x,
            face.y,
            face.width,
            face.height,
            look.faceRadius ??
                Math.max(
                    4,
                    radius -
                        Math.max(inset.side, Math.min(inset.top, inset.bottom)),
                ),
        );

    surface(context, faceShape, face, look.face, { depth: 0.3, shade: 0.3 });

    const deep = tones(colour).deep;
    const under = context.createLinearGradient(
        0,
        face.y,
        0,
        face.y + Math.min(face.height * 0.12, 24),
    );

    under.addColorStop(0, rgba(deep, 0.18));
    under.addColorStop(1, rgba(deep, 0));
    faceShape();
    context.fillStyle = under;
    context.fill();
}

/**
 * Paints an empty slot filling `box`: a well set into the board in a pale
 * tint of `colour`, its top and left inner edges in shade and its bottom
 * lit, as light from the top left falls into a recess — no outline.
 */
export function paintRecess(
    context: CanvasRenderingContext2D,
    box: Box,
    colour: number,
    radius: number,
): void {
    const t = tones(colour);
    const well = () =>
        roundedRect(context, box.x, box.y, box.width, box.height, radius);
    const fill = context.createLinearGradient(0, box.y, 0, box.y + box.height);

    fill.addColorStop(0, css(mix(colour, WARM_WHITE, 0.72)));
    fill.addColorStop(1, css(mix(colour, WARM_WHITE, 0.88)));
    well();
    context.fillStyle = fill;
    context.fill();

    // The inner shadow: a ring round the well, its blurred shadow cast inwards.
    const blur = Math.max(4, Math.min(box.width, box.height) * 0.03);

    context.save();
    well();
    context.clip();
    context.beginPath();
    context.rect(
        box.x - blur * 4,
        box.y - blur * 4,
        box.width + blur * 8,
        box.height + blur * 8,
    );
    roundedRect(context, box.x, box.y, box.width, box.height, radius, false);
    context.shadowColor = rgba(t.deep, 0.42);
    context.shadowBlur = blur;
    context.shadowOffsetX = blur * 0.4;
    context.shadowOffsetY = blur * 0.6;
    context.fillStyle = css(mix(colour, WARM_WHITE, 0.6));
    context.fill('evenodd');
    context.restore();
}
