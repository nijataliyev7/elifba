/**
 * The board's colours, all derived from the pack palette (frontend.md
 * rule 7, architecture §8 "Backdrops": palette colours at low opacity over
 * white). Nothing here names a colour of its own except the neutral text
 * colours used for contrast.
 */
const DARK_TEXT = '#1F2937';
const LIGHT_TEXT = '#FFFFFF';
const WHITE = '#FFFFFF';

type Rgb = { r: number; g: number; b: number };

function rgb(hex: string): Rgb | null {
    const match = /^#([0-9a-f]{2})([0-9a-f]{2})([0-9a-f]{2})$/i.exec(hex);

    return match
        ? {
              r: parseInt(match[1], 16),
              g: parseInt(match[2], 16),
              b: parseInt(match[3], 16),
          }
        : null;
}

function hex({ r, g, b }: Rgb): string {
    return `#${[r, g, b]
        .map((channel) => Math.round(channel).toString(16).padStart(2, '0'))
        .join('')
        .toUpperCase()}`;
}

/** `colour` at `opacity` over white. */
export function overWhite(colour: string, opacity: number): string {
    const c = rgb(colour);

    if (c === null) {
        return WHITE;
    }

    const mix = (channel: number) => 255 + (channel - 255) * opacity;

    return hex({ r: mix(c.r), g: mix(c.g), b: mix(c.b) });
}

/** Dark text on a light colour, white on a dark one. */
export function textOn(colour: string): string {
    const c = rgb(colour);

    if (c === null) {
        return DARK_TEXT;
    }

    return (0.299 * c.r + 0.587 * c.g + 0.114 * c.b) / 255 > 0.6
        ? DARK_TEXT
        : LIGHT_TEXT;
}

export type BoardColours = {
    /** Behind the canvas and around it. */
    background: string;
    /** The pack's main colour: the prompt's frame, the chosen student. */
    accent: string;
    text: string;
};

export function boardColours(palette: readonly string[]): BoardColours {
    const accent = palette.find((colour) => rgb(colour) !== null) ?? DARK_TEXT;

    return {
        background: overWhite(accent, 0.08),
        accent,
        text: DARK_TEXT,
    };
}
