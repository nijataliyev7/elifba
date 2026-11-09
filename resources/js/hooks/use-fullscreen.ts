import { useCallback, useSyncExternalStore } from 'react';

function subscribe(callback: () => void): () => void {
    document.addEventListener('fullscreenchange', callback);

    return () => document.removeEventListener('fullscreenchange', callback);
}

/**
 * Whether the page is in full screen, and a way to enter it. `enter` must
 * be called inside a user gesture (the board's «Başla»); a refusal is
 * ignored and the board plays in the window.
 */
export function useFullscreen(): { isFullscreen: boolean; enter: () => void } {
    const isFullscreen = useSyncExternalStore(
        subscribe,
        () => document.fullscreenElement !== null,
        () => false,
    );

    const enter = useCallback(() => {
        if (document.fullscreenElement !== null) {
            return;
        }

        document.documentElement
            .requestFullscreen?.({ navigationUI: 'hide' })
            .catch(() => {});
    }, []);

    return { isFullscreen, enter };
}
