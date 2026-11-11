/**
 * When the install banner shows (docs/frontend.md rule 11). Pure, so Vitest
 * covers it; the hook (`hooks/use-install-offer.ts`) feeds it the browser's
 * state.
 */

/** Chrome's `beforeinstallprompt` event, which TypeScript's DOM types lack. */
export interface BeforeInstallPromptEvent extends Event {
    prompt(): Promise<void>;
    readonly userChoice: Promise<{
        outcome: 'accepted' | 'dismissed';
        platform: string;
    }>;
}

/**
 * What the inline script in `app.blade.php` keeps on `window.elifbaInstall`:
 * Chrome's offer (null until Chrome makes one, and in Safari and Firefox,
 * which never do) and whether the app was installed during this visit.
 */
export type InstallOffer = {
    prompt: BeforeInstallPromptEvent | null;
    installed: boolean;
};

/** «İndi yox» hides the banner in this browser for two weeks. */
export const DISMISS_DAYS = 14;

/** `localStorage` key holding the time of the last «İndi yox» (ms since the epoch). */
export const DISMISSED_KEY = 'elifba.install-banner.dismissed-at';

const DAY_MS = 24 * 60 * 60 * 1000;

/**
 * An account's own pages: the welcome page, the auth pages and the shell of
 * either area (teacher or parent, architecture §1 "Accounts and roles"). Never the board (`games/board`, `lovhe`), the remote
 * (`games/remote`, `pult/*`) or the podium, which is made for the projector;
 * an unknown page shows nothing until it is listed here.
 */
const PAGES = [
    /^welcome$/,
    /^auth\//,
    /^classes\//,
    /^games\/setup$/,
    /^rankings\//,
    /^words\//,
    /^children\//,
    /^settings\//,
];

export function bannerAllowedOn(page: string): boolean {
    return PAGES.some((pattern) => pattern.test(page));
}

/** The stored dismissal time, or null when it is missing or not a time. */
export function parseDismissedAt(raw: string | null): number | null {
    if (raw === null || !/^\d+$/.test(raw)) {
        return null;
    }

    const value = Number(raw);

    return Number.isSafeInteger(value) && value > 0 ? value : null;
}

/**
 * Whether «İndi yox» still holds. A time after `now` (the clock was turned
 * back) does not, so a wrong clock never hides the banner for good.
 */
export function dismissedRecently(
    dismissedAt: number | null,
    now: number,
): boolean {
    return (
        dismissedAt !== null &&
        now >= dismissedAt &&
        now - dismissedAt < DISMISS_DAYS * DAY_MS
    );
}

export type BannerState = {
    /** The Inertia page component (`classes/index`). */
    page: string;
    /** Chrome made an offer that has not been used yet. */
    offered: boolean;
    /** The app was installed during this visit (`appinstalled`). */
    installed: boolean;
    /** This page runs in the installed app (`display-mode: standalone`). */
    standalone: boolean;
    dismissedAt: number | null;
    now: number;
};

export function showInstallBanner(state: BannerState): boolean {
    return (
        bannerAllowedOn(state.page) &&
        state.offered &&
        !state.installed &&
        !state.standalone &&
        !dismissedRecently(state.dismissedAt, state.now)
    );
}
