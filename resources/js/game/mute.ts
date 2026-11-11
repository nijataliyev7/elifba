/**
 * The board's silent mode (docs/architecture.md §4 "Board in the
 * browser"): this device's choice, kept in `localStorage`, never the
 * account's and never sent anywhere. Sound is on unless the device said
 * otherwise; storage that is missing, full or refused (a private window,
 * blocked site data) leaves the board as it is and never throws. The page
 * keeps the choice in memory (`muteStore`), so the toggle works even where
 * storage refuses to remember it, and every board of the page (a guest
 * board's next game) shares it.
 */
import type { StorageLike } from './outbox';

export const MUTE_STORAGE_KEY = 'elifba.board.muted';

/** Whether this device keeps the board silent; false when storage cannot say. */
export function readMuted(storage: StorageLike | null): boolean {
    try {
        return storage?.getItem(MUTE_STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

/** Remembers the choice on this device; a refusal only means it is not remembered. */
export function writeMuted(storage: StorageLike | null, muted: boolean): void {
    try {
        if (muted) {
            storage?.setItem(MUTE_STORAGE_KEY, '1');
        } else {
            storage?.removeItem(MUTE_STORAGE_KEY);
        }
    } catch {
        // Not remembered: the board stays as the toggle left it.
    }
}

/** The page's `localStorage`, or null where even reaching it throws. */
export function deviceStorage(): StorageLike | null {
    try {
        return typeof window === 'undefined' ? null : window.localStorage;
    } catch {
        return null;
    }
}

export type MuteStore = {
    subscribe: (listener: () => void) => () => void;
    /** The page's choice; read from storage the first time. */
    get: () => boolean;
    set: (muted: boolean) => void;
};

/** The page's silent mode, for `useSyncExternalStore` (the server renders sound on). */
export function muteStore(storage: () => StorageLike | null): MuteStore {
    let muted: boolean | null = null;
    const listeners = new Set<() => void>();

    return {
        subscribe: (listener) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },
        get: () => (muted ??= readMuted(storage())),
        set: (next) => {
            muted = next;
            writeMuted(storage(), next);
            listeners.forEach((listener) => listener());
        },
    };
}

/** The board pages' one store, over this device's `localStorage`. */
export const boardMute = muteStore(deviceStorage);
