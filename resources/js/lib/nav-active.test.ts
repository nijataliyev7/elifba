import { describe, expect, it } from 'vitest';
import { navAreas, navAreasFor } from '@/components/app/nav-areas';
import { activeArea, activePage, isWithin, pathOf } from '@/lib/nav-active';
import type { NavArea } from '@/lib/nav-active';

const teacher = navAreas.teacher;
const parent = navAreas.parent;

const areaKey = (url: string, areas = teacher): string | undefined =>
    activeArea(areas, url)?.key;

const pageTitle = (url: string, areas = teacher): string | undefined => {
    const area = activeArea(areas, url);

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
        expect(teacher.map((area) => area.key)).toEqual([
            'classes',
            'words',
            'settings',
        ]);
        expect(teacher.map((area) => area.pages.length)).toEqual([1, 1, 5]);
    });

    it('opens every area on its first page, inside the area', () => {
        for (const areas of [teacher, parent]) {
            for (const area of areas) {
                const first = pathOf(area.pages[0].href);

                expect(isWithin(first, area.base)).toBe(true);
                expect(areaKey(first, areas)).toBe(area.key);
            }
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
        expect(pageTitle('/settings/play')).toBe('settings.nav.play');
        expect(pageTitle('/settings/roles')).toBe('settings.nav.roles');
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

describe('the parent shell areas', () => {
    it('has the children and Settings, the same Settings as the teacher', () => {
        expect(parent.map((area) => area.key)).toEqual([
            'children',
            'settings',
        ]);
        expect(parent[1]).toBe(teacher[2]);
    });

    it('finds the children and leaves the class pages outside', () => {
        expect(areaKey('/children', parent)).toBe('children');
        expect(pageTitle('/children', parent)).toBe('nav.children');
        expect(pageTitle('/settings/roles', parent)).toBe('settings.nav.roles');
        expect(areaKey('/classes', parent)).toBeUndefined();
        expect(areaKey('/words', parent)).toBeUndefined();
    });
});

describe('the navigation of each area', () => {
    it('gives each role its own areas and the same Settings', () => {
        expect(navAreasFor('teacher').map((area) => area.key)).toEqual([
            'classes',
            'words',
            'settings',
        ]);
        expect(navAreasFor('parent').map((area) => area.key)).toEqual([
            'children',
            'settings',
        ]);
        expect(navAreasFor('parent').at(-1)).toBe(
            navAreasFor('teacher').at(-1),
        );
    });

    it('puts every child page under the children, never under Classes', () => {
        for (const url of [
            '/children',
            '/children/01JCHILD',
            '/children/01JCHILD/games/create',
        ]) {
            expect(areaKey(url, parent)).toBe('children');
            expect(pageTitle(url, parent)).toBe('nav.children');
            expect(areaKey(url, teacher)).toBeUndefined();
        }
    });

    it('offers no ranking, podium or words in the parent area', () => {
        const hrefs = parent.flatMap((area) =>
            area.pages.map((page) => pathOf(page.href)),
        );

        for (const href of hrefs) {
            expect(href).not.toMatch(/rankings|podium|words|classes/);
        }
    });
});

describe('navAreasFor', () => {
    it('gives each area its navigation and none before the role choice', () => {
        expect(navAreasFor('teacher')).toBe(teacher);
        expect(navAreasFor('parent')).toBe(parent);
        expect(navAreasFor(null)).toEqual([]);
    });
});

describe('activePage', () => {
    it('prefers the longest page when pages nest', () => {
        const icon = teacher[0].icon;
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
