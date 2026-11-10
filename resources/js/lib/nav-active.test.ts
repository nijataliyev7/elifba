import { describe, expect, it } from 'vitest';
import { navAreas } from '@/components/app/nav-areas';
import { activeArea, activePage, isWithin, pathOf } from '@/lib/nav-active';
import type { NavArea } from '@/lib/nav-active';

const areaKey = (url: string): string | undefined =>
    activeArea(navAreas, url)?.key;

const pageTitle = (url: string): string | undefined => {
    const area = activeArea(navAreas, url);

    return area ? activePage(area, url)?.title : undefined;
};

describe('pathOf', () => {
    it('drops the origin, the query and the hash', () => {
        expect(pathOf('https://elifba.test/words?pack=animals#top')).toBe(
            '/words',
        );
        expect(pathOf('/classes?archived=1')).toBe('/classes');
        expect(pathOf({ url: '/settings/profile', method: 'get' })).toBe(
            '/settings/profile',
        );
        expect(pathOf('')).toBe('/');
    });
});

describe('isWithin', () => {
    it('matches the base and the pages under it, segment by segment', () => {
        expect(isWithin('/classes', '/classes')).toBe(true);
        expect(isWithin('/classes/01J0/rankings', '/classes')).toBe(true);
        expect(isWithin('/classes-old', '/classes')).toBe(false);
        expect(isWithin('/class', '/classes')).toBe(false);
        expect(isWithin('/settings/profile', '/settings/')).toBe(true);
    });
});

describe('the teacher shell areas', () => {
    it('has one page per area except Settings, in the nav order', () => {
        expect(navAreas.map((area) => area.key)).toEqual([
            'classes',
            'words',
            'settings',
        ]);
        expect(navAreas.map((area) => area.pages.length)).toEqual([1, 1, 3]);
    });

    it('opens every area on its first page, inside the area', () => {
        for (const area of navAreas) {
            expect(isWithin(pathOf(area.pages[0].href), area.base)).toBe(true);
            expect(areaKey(pathOf(area.pages[0].href))).toBe(area.key);
        }
    });

    it('puts every class page under Classes', () => {
        for (const url of [
            '/classes',
            '/classes/01JCLASS',
            '/classes/01JCLASS/rankings?period=week&date=2026-11-09',
            '/classes/01JCLASS/podium',
            '/classes/01JCLASS/games/create',
        ]) {
            expect(areaKey(url)).toBe('classes');
            expect(pageTitle(url)).toBe('nav.classes');
        }
    });

    it('finds the words page with its pack query', () => {
        expect(areaKey('/words?pack=animals')).toBe('words');
        expect(pageTitle('/words?pack=animals')).toBe('nav.words');
    });

    it('finds each settings page', () => {
        expect(pageTitle('/settings/profile')).toBe('settings.nav.profile');
        expect(pageTitle('/settings/security')).toBe('settings.nav.security');
        expect(pageTitle('https://elifba.test/settings/appearance')).toBe(
            'settings.nav.appearance',
        );
        expect(areaKey('/settings')).toBe('settings');
        expect(pageTitle('/settings')).toBeUndefined();
    });

    it('leaves pages outside the shell without an area', () => {
        for (const url of [
            '/',
            '/dashboard',
            '/games/01JGAME/board',
            '/pult',
        ]) {
            expect(areaKey(url)).toBeUndefined();
        }
    });
});

describe('activePage', () => {
    it('prefers the longest page when pages nest', () => {
        const icon = navAreas[0].icon;
        const area: NavArea = {
            key: 'nested',
            title: 'nav.classes',
            icon,
            base: '/a',
            pages: [
                { title: 'outer', href: '/a', icon },
                { title: 'inner', href: '/a/b', icon },
            ],
        };

        expect(activePage(area, '/a/b/c')?.title).toBe('inner');
        expect(activePage(area, '/a/x')?.title).toBe('outer');
    });
});
