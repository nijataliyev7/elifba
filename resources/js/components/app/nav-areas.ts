import { usePage } from '@inertiajs/react';
import {
    BookOpenText,
    Gamepad2,
    House,
    Palette,
    School,
    Settings,
    ShieldCheck,
    UserCog,
    UserRound,
} from 'lucide-react';
import type { NavArea } from '@/lib/nav-active';
import { edit as editAppearance } from '@/routes/appearance';
import { index as children } from '@/routes/children';
import { index as classes } from '@/routes/classes';
import { edit as editPlaySettings } from '@/routes/play-settings';
import { edit as editProfile } from '@/routes/profile';
import { edit as editRoles } from '@/routes/roles';
import { edit as editSecurity } from '@/routes/security';
import { index as words } from '@/routes/words';
import type { Role } from '@/types';

const classesArea: NavArea = {
    key: 'classes',
    title: 'nav.classes',
    icon: School,
    base: '/classes',
    pages: [{ title: 'nav.classes', href: classes(), icon: School }],
};

const wordsArea: NavArea = {
    key: 'words',
    title: 'nav.words',
    icon: BookOpenText,
    base: '/words',
    pages: [{ title: 'nav.words', href: words(), icon: BookOpenText }],
};

const childrenArea: NavArea = {
    key: 'children',
    title: 'nav.children',
    icon: House,
    base: '/children',
    pages: [{ title: 'nav.children', href: children(), icon: House }],
};

const settingsArea: NavArea = {
    key: 'settings',
    title: 'nav.settings',
    icon: Settings,
    base: '/settings',
    pages: [
        { title: 'settings.nav.profile', href: editProfile(), icon: UserRound },
        {
            title: 'settings.nav.security',
            href: editSecurity(),
            icon: ShieldCheck,
        },
        {
            title: 'settings.nav.appearance',
            href: editAppearance(),
            icon: Palette,
        },
        {
            title: 'settings.nav.play',
            href: editPlaySettings(),
            icon: Gamepad2,
        },
        { title: 'settings.nav.roles', href: editRoles(), icon: UserCog },
    ],
};

/**
 * The shell's one nav definition (docs/frontend.md §1), one navigation per
 * area (architecture §1 "Accounts and roles"): the sidebar, the bottom bar
 * and the pill strip read the one of the page's area. Titles are
 * translation keys.
 */
export const navAreas: Record<Role, NavArea[]> = {
    teacher: [classesArea, wordsArea, settingsArea],
    parent: [childrenArea, settingsArea],
};

/** The navigation of an area; none before the role choice. */
export function navAreasFor(area: Role | null): NavArea[] {
    return area === null ? [] : navAreas[area];
}

/** The navigation of the current page's area (the shared `auth.area`). */
export function useNavAreas(): NavArea[] {
    return navAreasFor(usePage().props.auth.area);
}
