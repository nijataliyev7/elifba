import { useEffect } from 'react';

/**
 * Holds a Screen Wake Lock while `active`, so the projector laptop does not
 * sleep mid-game (docs/architecture.md §4, "Reconnects"). The browser drops
 * the lock when the page is hidden; it is taken again when the page is
 * visible. Browsers without the API simply sleep as before.
 */
export function useWakeLock(active: boolean): void {
    useEffect(() => {
        if (!active || !('wakeLock' in navigator)) {
            return;
        }

        let lock: WakeLockSentinel | null = null;
        let released = false;

        const take = async () => {
            if (document.visibilityState !== 'visible' || released) {
                return;
            }

            try {
                const taken = await navigator.wakeLock.request('screen');

                if (released) {
                    void taken.release();
                } else {
                    lock = taken;
                }
            } catch {
                // Refused (battery saver, no permission): play on.
            }
        };

        const onVisibility = () => {
            if (document.visibilityState === 'visible') {
                void take();
            }
        };

        void take();
        document.addEventListener('visibilitychange', onVisibility);

        return () => {
            released = true;
            document.removeEventListener('visibilitychange', onVisibility);
            void lock?.release();
        };
    }, [active]);
}
