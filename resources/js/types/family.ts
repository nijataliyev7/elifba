/*
 * Page props of a parent's children (`Family\…` controllers, ADR 011). A
 * child is a first name and an avatar (ADR 013); stars are the child's own,
 * never beside a sibling's.
 */

/** A child on «Uşaqlarım», in the parent's order, with today's stars only. */
export type ChildRow = {
    id: string;
    display_name: string;
    /** Key from `content/avatars.json`. */
    avatar: string;
    stars_today: number;
};

/** The child on their own page. */
export type Child = {
    id: string;
    display_name: string;
    avatar: string;
    /** Lowercase letters the child has learned, in alphabet order. */
    learned_letters: string[];
};

/** A child's stars (`ChildStarsData`): the last seven Baku days, oldest first. */
export type ChildStars = {
    today: number;
    week: number;
    total: number;
    days: { date: string; label: string; stars: number }[];
};
