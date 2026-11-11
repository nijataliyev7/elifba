import { describe, expect, it, vi } from 'vite-plus/test';
import type { Timers } from '@/game/outbox';
import { releaseScreen, screenRelease } from '@/hooks/use-fullscreen';

/*
 * Leaving the board gives the screen back (architecture §4 "Board in the
 * browser"): out of full screen, orientation unlocked, whatever the browser
 * lacks or refuses — and nothing on React's development double mount.
 */

function page(inFullscreen: boolean, exit?: () => Promise<void> | void) {
    return {
        fullscreenElement: inFullscreen ? {} : null,
        exitFullscreen: exit ?? vi.fn(async () => {}),
    };
}

function manualTimers(): Timers & { flush: () => void; size: () => number } {
    const pending = new Map<number, () => void>();
    let next = 1;

    return {
        set: (callback) => {
            pending.set(next, callback);

            return next++;
        },
        clear: (handle) => void pending.delete(handle as number),
        flush: () => {
            const due = [...pending.values()];

            pending.clear();
            due.forEach((callback) => callback());
        },
        size: () => pending.size,
    };
}

describe('releasing the screen', () => {
    it('leaves full screen and unlocks the orientation', () => {
        const document = page(true);
        const orientation = { unlock: vi.fn() };

        releaseScreen({ document, orientation });

        expect(document.exitFullscreen).toHaveBeenCalledTimes(1);
        expect(orientation.unlock).toHaveBeenCalledTimes(1);
    });

    it('does not ask to leave full screen when the page is not in it', () => {
        const document = page(false);
        const orientation = { unlock: vi.fn() };

        releaseScreen({ document, orientation });

        expect(document.exitFullscreen).not.toHaveBeenCalled();
        expect(orientation.unlock).toHaveBeenCalledTimes(1);
    });

    it('ignores a refusal', async () => {
        const rejected = vi.fn(() =>
            Promise.reject(new TypeError('Not active')),
        );
        const throwing = vi.fn((): void => {
            throw new TypeError('Denied');
        });
        const unlock = vi.fn(() => {
            throw new DOMException('Not supported', 'NotSupportedError');
        });

        expect(() =>
            releaseScreen({
                document: page(true, rejected),
                orientation: { unlock },
            }),
        ).not.toThrow();
        expect(() =>
            releaseScreen({ document: page(true, throwing) }),
        ).not.toThrow();
        // A rejected exit is caught, not left unhandled.
        await new Promise((resolve) => setTimeout(resolve, 0));
        expect(rejected).toHaveBeenCalledTimes(1);
        expect(unlock).toHaveBeenCalledTimes(1);
    });

    it('ignores a browser without the APIs (an iPhone, a server render)', () => {
        expect(() => releaseScreen({})).not.toThrow();
        expect(() =>
            releaseScreen({
                document: { fullscreenElement: {} },
                orientation: {},
            }),
        ).not.toThrow();
        expect(() => releaseScreen()).not.toThrow();
    });

    it('releases a tick after a board page is left', () => {
        const release = vi.fn();
        const timers = manualTimers();
        const pages = screenRelease(release, timers);

        pages.hold();
        pages.leave();

        expect(release).not.toHaveBeenCalled();

        timers.flush();

        expect(release).toHaveBeenCalledTimes(1);
    });

    it("releases nothing on React's development double mount", () => {
        const release = vi.fn();
        const timers = manualTimers();
        const pages = screenRelease(release, timers);

        // mount, unmount, mount
        pages.hold();
        pages.leave();
        pages.hold();
        timers.flush();

        expect(release).not.toHaveBeenCalled();
        expect(timers.size()).toBe(0);
    });

    it('releases once however often the page is left', () => {
        const release = vi.fn();
        const timers = manualTimers();
        const pages = screenRelease(release, timers);

        pages.leave();
        pages.leave();
        timers.flush();

        expect(release).toHaveBeenCalledTimes(1);
    });
});
