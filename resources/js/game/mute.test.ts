import { describe, expect, it } from 'vite-plus/test';
import { deviceStorage, MUTE_STORAGE_KEY, readMuted, writeMuted } from './mute';
import type { StorageLike } from './outbox';

/*
 * The board's silent mode (architecture §4 "Board in the browser"): one
 * flag in this device's `localStorage`, sound on by default, and storage
 * that refuses never breaks the board.
 */

function memoryStorage(): StorageLike & { items: Map<string, string> } {
    const items = new Map<string, string>();

    return {
        items,
        getItem: (key) => items.get(key) ?? null,
        setItem: (key, value) => void items.set(key, value),
        removeItem: (key) => void items.delete(key),
    };
}

const refusing: StorageLike = {
    getItem: () => {
        throw new DOMException('The operation is insecure.', 'SecurityError');
    },
    setItem: () => {
        throw new DOMException('Quota exceeded', 'QuotaExceededError');
    },
    removeItem: () => {
        throw new DOMException('The operation is insecure.', 'SecurityError');
    },
};

describe('silent mode', () => {
    it('plays sound when nothing is stored', () => {
        expect(readMuted(memoryStorage())).toBe(false);
        expect(readMuted(null)).toBe(false);
    });

    it('remembers the choice on this device and forgets it when sound is back', () => {
        const storage = memoryStorage();

        writeMuted(storage, true);

        expect(storage.items.get(MUTE_STORAGE_KEY)).toBe('1');
        expect(readMuted(storage)).toBe(true);

        writeMuted(storage, false);

        expect(storage.items.has(MUTE_STORAGE_KEY)).toBe(false);
        expect(readMuted(storage)).toBe(false);
    });

    it('reads anything but its own value as sound on', () => {
        const storage = memoryStorage();

        storage.items.set(MUTE_STORAGE_KEY, 'true');

        expect(readMuted(storage)).toBe(false);
    });

    it('never throws when storage refuses', () => {
        expect(readMuted(refusing)).toBe(false);
        expect(() => writeMuted(refusing, true)).not.toThrow();
        expect(() => writeMuted(refusing, false)).not.toThrow();
        expect(() => writeMuted(null, true)).not.toThrow();
    });

    it('has no device storage outside a browser', () => {
        expect(deviceStorage()).toBeNull();
    });
});
