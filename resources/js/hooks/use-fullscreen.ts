import { useCallback, useEffect, useSyncExternalStore } from 'react';
import type { Timers } from '@/game/outbox';

function subscribe(callback: () => void): () => void {
    document.addEventListener('fullscreenchange', callback);

    return () => document.removeEventListener('fullscreenchange', callback);
}

const noSubscription = () => () => {};

/**
 * Whether the page can go full screen and is in it, and a way to enter it.
 * `enter` must be called inside a user gesture (the board's «Başla»); it
 * resolves once the page is in full screen, or at once when the browser
 * refuses or cannot (an iPhone has no element full screen), and the board
 * plays in the window.
 */
export function useFullscreen(): {
    isSupported: boolean;
    isFullscreen: boolean;
    enter: () => Promise<void>;
} {
    const isSupported = useSyncExternalStore(
        noSubscription,
        () => document.fullscreenEnabled === true,
        () => false,
    );
    const isFullscreen = useSyncExternalStore(
        subscribe,
        () => document.fullscreenElement !== null,
        () => false,
    );

    const enter = useCallback(async (): Promise<void> => {
        if (
            document.fullscreenEnabled !== true ||
            document.fullscreenElement !== null
        ) {
            return;
        }

        try {
            await document.documentElement.requestFullscreen?.({
                navigationUI: 'hide',
            });
        } catch {
            // Refused: the board plays in the window.
        }
    }, []);

    return { isSupported, isFullscreen, enter };
}

/**
 * Asks a phone to stay in landscape, where the 16:9 board fits. Browsers
 * allow it only in full screen, and many (desktop, iPhone) never: a
 * missing API or a refusal is ignored.
 */
export function lockLandscape(): void {
    const orientation = (
        typeof screen === 'undefined' ? undefined : screen.orientation
    ) as
        | (ScreenOrientation & {
              lock?: (orientation: 'landscape') => Promise<void>;
          })
        | undefined;

    if (typeof orientation?.lock !== 'function') {
        return;
    }

    orientation.lock('landscape').catch(() => {});
}

/**
 * What `releaseScreen()` touches: the page's full screen and the screen's
 * orientation lock. Either may be missing (a server render, an iPhone, an
 * old browser); tests pass fakes.
 */
export type ScreenParts = {
    document?: {
        readonly fullscreenElement: object | null;
        exitFullscreen?: () => Promise<void> | void;
    };
    orientation?: { unlock?: () => void };
};

function currentScreen(): ScreenParts {
    return {
        document: typeof document === 'undefined' ? undefined : document,
        orientation:
            typeof screen === 'undefined' ? undefined : screen.orientation,
    };
}

/**
 * Gives the screen back when the board is left (architecture §4 "Board in
 * the browser"): unlocks the orientation, so a phone turns upright again,
 * and leaves full screen if the page is in it. A missing API, a refusal or
 * a rejected promise is ignored; nothing here needs a user gesture.
 */
export function releaseScreen(parts: ScreenParts = currentScreen()): void {
    const { document: page, orientation } = parts;

    try {
        orientation?.unlock?.();
    } catch {
        // Never locked, or the browser cannot unlock: nothing to give back.
    }

    if (
        page === undefined ||
        page.fullscreenElement === null ||
        typeof page.exitFullscreen !== 'function'
    ) {
        return;
    }

    try {
        void Promise.resolve(page.exitFullscreen()).catch(() => {});
    } catch {
        // Refused: the page stays as it is.
    }
}

const defaultTimers: Timers = {
    set: (callback, ms) => setTimeout(callback, ms),
    clear: (handle) => clearTimeout(handle as ReturnType<typeof setTimeout>),
};

/**
 * When a board page that left really is gone. `leave()` releases a tick
 * later, and `hold()` — a board page mounting in that tick — cancels it:
 * React's development double mount (mount, unmount, mount) releases
 * nothing, and neither does one board page following another.
 */
export function screenRelease(
    release: () => void,
    timers: Timers = defaultTimers,
): { hold: () => void; leave: () => void } {
    let pending: unknown = null;

    const cancel = () => {
        if (pending !== null) {
            timers.clear(pending);
            pending = null;
        }
    };

    return {
        hold: cancel,
        leave: () => {
            cancel();
            pending = timers.set(() => {
                pending = null;
                release();
            }, 0);
        },
    };
}

const boardPages = screenRelease(() => releaseScreen());

/**
 * A board page holds the screen while it is mounted and releases it once
 * it is left (`games/board`, and `lovhe` across all its games).
 */
export function useReleaseScreenOnLeave(): void {
    useEffect(() => {
        boardPages.hold();

        return () => boardPages.leave();
    }, []);
}
