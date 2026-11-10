import { useCallback, useSyncExternalStore } from 'react';

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
