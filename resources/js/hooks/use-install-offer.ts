import { useState, useSyncExternalStore } from 'react';
import {
    DISMISSED_KEY,
    parseDismissedAt,
    showInstallBanner,
} from '@/lib/install-banner';
import type { InstallOffer } from '@/lib/install-banner';

/** Fired by the inline script in app.blade.php when the offer changes. */
const CHANGE = 'elifba:install';

const STANDALONE =
    '(display-mode: standalone), (display-mode: window-controls-overlay), (display-mode: minimal-ui)';

const NO_OFFER: InstallOffer = { prompt: null, installed: false };

/**
 * The last «İndi yox» of this page load, for a browser whose storage throws
 * (a private window): the banner then stays away until the next visit.
 */
let dismissedThisVisit: number | null = null;

function subscribe(onChange: () => void): () => void {
    const media = window.matchMedia(STANDALONE);
    window.addEventListener(CHANGE, onChange);
    media.addEventListener('change', onChange);

    return () => {
        window.removeEventListener(CHANGE, onChange);
        media.removeEventListener('change', onChange);
    };
}

function currentOffer(): InstallOffer {
    return window.elifbaInstall ?? NO_OFFER;
}

function isStandalone(): boolean {
    return window.matchMedia(STANDALONE).matches;
}

function setOffer(offer: InstallOffer): void {
    window.elifbaInstall = offer;
    window.dispatchEvent(new Event(CHANGE));
}

function readDismissedAt(): number | null {
    try {
        return (
            parseDismissedAt(window.localStorage.getItem(DISMISSED_KEY)) ??
            dismissedThisVisit
        );
    } catch {
        return dismissedThisVisit;
    }
}

function rememberDismissal(at: number): void {
    dismissedThisVisit = at;

    try {
        window.localStorage.setItem(DISMISSED_KEY, String(at));
    } catch {
        // Storage is off: `dismissedThisVisit` holds it for this page load.
    }
}

/**
 * Chrome's offer to install Elifba, for the install banner (docs/frontend.md
 * rule 11): whether the banner shows on `page`, «Quraşdır» (Chrome's own
 * install dialog) and «İndi yox» (two weeks in this browser).
 */
export function useInstallOffer(page: string): {
    visible: boolean;
    install: () => Promise<void>;
    dismiss: () => void;
} {
    const offer = useSyncExternalStore(subscribe, currentOffer, () => NO_OFFER);
    const standalone = useSyncExternalStore(
        subscribe,
        isStandalone,
        () => false,
    );
    const [clock, setClock] = useState(() => ({
        dismissedAt: readDismissedAt(),
        now: Date.now(),
    }));

    const visible = showInstallBanner({
        page,
        offered: offer.prompt !== null,
        installed: offer.installed,
        standalone,
        dismissedAt: clock.dismissedAt,
        now: clock.now,
    });

    const dismiss = (): void => {
        const at = Date.now();
        rememberDismissal(at);
        setClock({ dismissedAt: at, now: at });
    };

    const install = async (): Promise<void> => {
        const prompt = offer.prompt;

        if (prompt === null) {
            return;
        }

        // Chrome's offer can be used once; it makes a new one on a later visit.
        setOffer({ prompt: null, installed: false });

        try {
            await prompt.prompt();
            const choice = await prompt.userChoice;

            // Cancelling Chrome's dialog is a «not now» too. Accepting fires
            // `appinstalled`, which the inline script records.
            if (choice.outcome === 'dismissed') {
                dismiss();
            }
        } catch {
            // Chrome refused to show the dialog; the banner is already gone.
        }
    };

    return { visible, install, dismiss };
}
