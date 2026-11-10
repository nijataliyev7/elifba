import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
import { toUrl } from '@/lib/utils';

type Href = NonNullable<InertiaLinkProps['href']>;

/** A page inside an area: a pill on the phone, a sub-item in the sidebar. */
export type NavPage = {
    /** A translation key, translated where it is rendered. */
    title: string;
    href: Href;
    icon: LucideIcon;
};

/**
 * An area of the teacher shell (docs/frontend.md §1): a tab in the bottom
 * bar, an item or a group in the sidebar. Every URL under `base` belongs to
 * it, and it opens on its first page.
 */
export type NavArea = {
    key: string;
    /** A translation key, translated where it is rendered. */
    title: string;
    icon: LucideIcon;
    /** The path prefix that marks the area open, such as `/classes`. */
    base: string;
    pages: [NavPage, ...NavPage[]];
};

/** The path of a URL or a Wayfinder route, without its query or hash. */
export function pathOf(url: Href | string): string {
    const path = toUrl(url).replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]+/i, '');

    return path.split(/[?#]/, 1)[0] || '/';
}

/** Whether `path` is `base` itself or a page under it, segment by segment. */
export function isWithin(path: string, base: string): boolean {
    const trimmed = base.replace(/\/+$/, '');

    return path === trimmed || path.startsWith(`${trimmed}/`);
}

/** The area the URL is in, or `undefined` outside the shell's areas. */
export function activeArea(
    areas: readonly NavArea[],
    url: string,
): NavArea | undefined {
    const path = pathOf(url);

    return areas.find((area) => isWithin(path, area.base));
}

/**
 * The page of `area` the URL is on: the page itself or one under it, the
 * longest match when pages nest.
 */
export function activePage(area: NavArea, url: string): NavPage | undefined {
    const path = pathOf(url);

    return area.pages
        .filter((page) => isWithin(path, pathOf(page.href)))
        .sort((a, b) => pathOf(b.href).length - pathOf(a.href).length)[0];
}
