import type { Avatar } from './classroom';

/*
 * Page props of the rankings page (`RankingController`). Dates are
 * `YYYY-MM-DD` strings on the Baku calendar, chosen by the server; labels
 * are formatted by the server in the interface language.
 */

export type RankingPeriod = 'day' | 'week';

/** One student's row (`RankedStudentData::toArray()`). */
export type RankedStudent = {
    id: string;
    display_name: string;
    /** Key from `content/avatars.json`. */
    avatar: string;
    points: number;
    /** Dense place (ties share one); null with 0 points. */
    place: number | null;
    /** "1-ci" / "1st", built on the server; null with 0 points. */
    place_label: string | null;
};

export type RankingProps = {
    schoolClass: { id: string; name: string; archived: boolean };
    period: RankingPeriod;
    /** The chosen date; a week is the Monday–Sunday week that holds it. */
    date: string;
    /** "9 noyabr 2026" or "9–15 noyabr 2026". */
    label: string;
    /** The window holds today in Baku. */
    current: boolean;
    /** A date in the window before. */
    previous: string;
    /** A date in the window after; null when it has not started. */
    next: string | null;
    /** Ranked students first (place, then name), then those with 0 points by name. */
    students: RankedStudent[];
    avatars: Avatar[];
};

/** One place of the podium on the teacher's page (`PodiumPlaceData::toArray()`). */
export type PodiumPlace = {
    /** 1, 2 or 3; tied students share it. */
    place: number;
    /** "1-ci" / "1st", built on the server. */
    place_label: string;
    points: number;
    /** In Azerbaijani order. */
    students: { id: string; display_name: string; avatar: string }[];
};

/**
 * The share card (`ShareCardData::toArray()`, architecture §6): exactly what
 * the 1080×1920 picture shows, every text already in the interface language.
 * No ids, class, teacher, link or time.
 */
export type ShareCard = {
    /** "Günün qalibləri" / "Həftənin qalibləri". */
    title: string;
    /** "11 noyabr 2026" or "9–15 noyabr 2026". */
    label: string;
    places: {
        place: number;
        place_label: string;
        /** "6 xal" / "6 points". */
        points_label: string;
        students: {
            display_name: string;
            /** A path on the page's own origin; null: the avatar is gone. */
            avatar_url: string | null;
        }[];
    }[];
    /** "Elifba". */
    wordmark: string;
    /** The instance's host, e.g. "elifba.az". */
    domain: string;
    /** `elifba-{gunun|heftenin}-qalibleri-{date}.png`. */
    file_name: string;
};

export type PodiumProps = Omit<RankingProps, 'students'> & {
    /** Places 1–3; empty when nobody has points. */
    podium: PodiumPlace[];
    /** Null when the podium is empty: nothing to share. */
    card: ShareCard | null;
};
