import {
    BookOpenText,
    Gamepad2,
    Palette,
    School,
    Settings,
    ShieldCheck,
    UserRound,
} from 'lucide-react';
import type { NavArea } from '@/lib/nav-active';
import { edit as editAppearance } from '@/routes/appearance';
import { index as classes } from '@/routes/classes';
import { edit as editPlaySettings } from '@/routes/play-settings';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import { index as words } from '@/routes/words';

/**
 * The teacher shell's one nav definition (docs/frontend.md §1), read by the
 * sidebar, the bottom bar and the pill strip. Titles are translation keys.
 */
export const navAreas: NavArea[] = [
    {
        key: 'classes',
        title: 'nav.classes',
        icon: School,
        base: '/classes',
        pages: [{ title: 'nav.classes', href: classes(), icon: School }],
    },
    {
        key: 'words',
        title: 'nav.words',
        icon: BookOpenText,
        base: '/words',
        pages: [{ title: 'nav.words', href: words(), icon: BookOpenText }],
    },
    {
        key: 'settings',
        title: 'nav.settings',
        icon: Settings,
        base: '/settings',
        pages: [
            {
                title: 'settings.nav.profile',
                href: editProfile(),
                icon: UserRound,
            },
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
        ],
    },
];
