/**
 * The medal colours of places 1–3 — gold, silver, bronze — on the podium
 * page and on the share card alike (docs/frontend.md §7). Fills from
 * OpenMoji's palette (architecture §8), so they sit well with the avatars
 * and the decoration; text on them is always dark.
 */
export const PLACE_COLORS: Record<number, { fill: string; ring: string }> = {
    1: { fill: '#FCEA2B', ring: '#F1B31C' },
    2: { fill: '#D0CFCE', ring: '#9B9B9A' },
    3: { fill: '#F4AA41', ring: '#E27022' },
};

export function placeColors(place: number): { fill: string; ring: string } {
    return PLACE_COLORS[place] ?? PLACE_COLORS[3];
}
