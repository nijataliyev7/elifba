import { readdirSync } from 'node:fs';
import { join, relative } from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    bannerAllowedOn,
    DISMISS_DAYS,
    dismissedRecently,
    parseDismissedAt,
    showInstallBanner,
} from '@/lib/install-banner';
import type { BannerState } from '@/lib/install-banner';

const DAY = 24 * 60 * 60 * 1000;
const NOW = Date.UTC(2026, 10, 10, 9, 0, 0);

const state = (overrides: Partial<BannerState> = {}): BannerState => ({
    page: 'classes/index',
    offered: true,
    installed: false,
    standalone: false,
    dismissedAt: null,
    now: NOW,
    ...overrides,
});

/** Every Inertia page component, as `usePage().component` names it. */
function pages(): string[] {
    const root = join(process.cwd(), 'resources/js/pages');
    const names: string[] = [];

    const walk = (dir: string): void => {
        for (const entry of readdirSync(dir, { withFileTypes: true })) {
            const path = join(dir, entry.name);

            if (entry.isDirectory()) {
                walk(path);
            } else if (entry.name.endsWith('.tsx')) {
                names.push(relative(root, path).replace(/\.tsx$/, ''));
            }
        }
    };

    walk(root);

    return names.sort();
}

describe('bannerAllowedOn', () => {
    it('allows the welcome page, the auth pages and the teacher shell', () => {
        for (const page of [
            'welcome',
            'auth/login',
            'auth/register',
            'auth/google-register',
            'classes/index',
            'classes/show',
            'games/setup',
            'rankings/show',
            'words/index',
            'settings/profile',
            'settings/play',
        ]) {
            expect(bannerAllowedOn(page), page).toBe(true);
        }
    });

    it('never allows the board, the remote or the podium', () => {
        for (const page of [
            'games/board',
            'lovhe',
            'games/remote',
            'pult/index',
            'pult/guest-board',
            'podium',
        ]) {
            expect(bannerAllowedOn(page), page).toBe(false);
        }
    });

    it('decides every page that exists, and only the listed ones', () => {
        const allowed = pages().filter(bannerAllowedOn);
        const refused = pages().filter((page) => !bannerAllowedOn(page));

        expect(refused).toEqual([
            'credits',
            'games/board',
            'games/remote',
            'lovhe',
            'podium',
            'privacy',
            'pult/guest-board',
            'pult/index',
        ]);
        expect(allowed).toContain('welcome');
        expect(allowed).toContain('classes/index');
    });

    it('does not match a page by a prefix of its name', () => {
        expect(bannerAllowedOn('welcome/other')).toBe(false);
        expect(bannerAllowedOn('games/setup-board')).toBe(false);
        expect(bannerAllowedOn('podium/classes/x')).toBe(false);
    });
});

describe('parseDismissedAt', () => {
    it('reads a stored time', () => {
        expect(parseDismissedAt(String(NOW))).toBe(NOW);
    });

    it('reads nothing from a missing or broken value', () => {
        for (const raw of [null, '', 'abc', '-5', '1.5', '0', '1e12', ' 12']) {
            expect(parseDismissedAt(raw), String(raw)).toBeNull();
        }
    });
});

describe('dismissedRecently', () => {
    it('holds for two weeks after «İndi yox»', () => {
        expect(dismissedRecently(NOW, NOW)).toBe(true);
        expect(dismissedRecently(NOW - DAY, NOW)).toBe(true);
        expect(dismissedRecently(NOW - DISMISS_DAYS * DAY + 1, NOW)).toBe(true);
    });

    it('ends after two weeks', () => {
        expect(dismissedRecently(NOW - DISMISS_DAYS * DAY, NOW)).toBe(false);
        expect(dismissedRecently(NOW - 90 * DAY, NOW)).toBe(false);
    });

    it('does not hold without a dismissal or with a time in the future', () => {
        expect(dismissedRecently(null, NOW)).toBe(false);
        expect(dismissedRecently(NOW + DAY, NOW)).toBe(false);
    });
});

describe('showInstallBanner', () => {
    it('shows when Chrome offers installation on a teacher page', () => {
        expect(showInstallBanner(state())).toBe(true);
        expect(showInstallBanner(state({ page: 'welcome' }))).toBe(true);
        expect(showInstallBanner(state({ page: 'auth/login' }))).toBe(true);
    });

    it('shows nothing without an offer (Safari, Firefox, or once used)', () => {
        expect(showInstallBanner(state({ offered: false }))).toBe(false);
    });

    it('hides once the app is installed or runs installed', () => {
        expect(showInstallBanner(state({ installed: true }))).toBe(false);
        expect(showInstallBanner(state({ standalone: true }))).toBe(false);
    });

    it('hides on the board and the remote even with an offer', () => {
        expect(showInstallBanner(state({ page: 'games/board' }))).toBe(false);
        expect(showInstallBanner(state({ page: 'pult/index' }))).toBe(false);
    });

    it('hides for two weeks after «İndi yox», then comes back', () => {
        expect(showInstallBanner(state({ dismissedAt: NOW - DAY }))).toBe(
            false,
        );
        expect(
            showInstallBanner(
                state({ dismissedAt: NOW - (DISMISS_DAYS + 1) * DAY }),
            ),
        ).toBe(true);
    });
});
