import { Link, router, usePage } from '@inertiajs/react';
import {
    Check,
    ChevronDown,
    Languages,
    LogOut,
    Monitor,
    Moon,
    Settings,
    Sun,
} from 'lucide-react';
import { useState } from 'react';
import { useLocaleChoice } from '@/components/language-switcher';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { useAppearance } from '@/hooks/use-appearance';
import type { Appearance } from '@/hooks/use-appearance';
import { useInitials } from '@/hooks/use-initials';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';

const themes: { mode: Appearance; title: string; icon: typeof Sun }[] = [
    { mode: 'light', title: 'settings.appearance.light', icon: Sun },
    { mode: 'dark', title: 'settings.appearance.dark', icon: Moon },
    { mode: 'system', title: 'settings.appearance.system', icon: Monitor },
];

/**
 * The teacher's menu in the shell's header, on every viewport
 * (docs/frontend.md §1, §2): who is signed in, the language and the theme,
 * Settings and logging out. Language and theme open in place, under their
 * row, so nothing slides off a phone's screen.
 */
export function UserMenu() {
    const { auth } = usePage().props;
    const { t } = useTranslation();
    const { locale, locales, choose } = useLocaleChoice();
    const { appearance, updateAppearance } = useAppearance();
    const getInitials = useInitials();
    const cleanup = useMobileNavigation();
    const [open, setOpen] = useState<'language' | 'theme' | null>(null);

    if (!auth.user) {
        return null;
    }

    const user = auth.user;
    const theme = themes.find((item) => item.mode === appearance) ?? themes[2];

    const toggle = (row: 'language' | 'theme') => (event: Event) => {
        event.preventDefault();
        setOpen((current) => (current === row ? null : row));
    };

    const handleLogout = () => {
        cleanup();
        router.flushAll();
    };

    return (
        <DropdownMenu onOpenChange={() => setOpen(null)}>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    className="h-9 min-w-0 gap-2 px-1.5 sm:px-2"
                    aria-label={user.name}
                    data-test="user-menu-button"
                >
                    <Avatar className="size-7 overflow-hidden rounded-full">
                        <AvatarImage src={user.avatar} alt="" />
                        <AvatarFallback className="bg-neutral-200 text-xs text-black dark:bg-neutral-700 dark:text-white">
                            {getInitials(user.name)}
                        </AvatarFallback>
                    </Avatar>
                    <span className="hidden max-w-40 truncate text-sm font-medium sm:block">
                        {user.name}
                    </span>
                    <ChevronDown
                        aria-hidden
                        className="size-4 shrink-0 text-muted-foreground"
                    />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent
                align="end"
                className="w-64 max-w-[calc(100vw-1rem)]"
            >
                <DropdownMenuLabel className="p-0 font-normal">
                    <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                        <UserInfo user={user} showEmail />
                    </div>
                </DropdownMenuLabel>
                <DropdownMenuSeparator />

                <DropdownMenuItem
                    onSelect={toggle('language')}
                    aria-expanded={open === 'language'}
                >
                    <Languages aria-hidden />
                    <span className="flex-1">{t('nav.language')}</span>
                    <span className="text-xs text-muted-foreground">
                        {locales[locale]}
                    </span>
                    <ChevronDown
                        aria-hidden
                        className={cn(
                            'transition-transform',
                            open === 'language' && 'rotate-180',
                        )}
                    />
                </DropdownMenuItem>
                {open === 'language' &&
                    Object.entries(locales).map(([code, name]) => (
                        <DropdownMenuItem
                            key={code}
                            lang={code}
                            className="pl-8"
                            data-test={`locale-${code}`}
                            onSelect={() => choose(code)}
                        >
                            <span className="flex-1">{name}</span>
                            {code === locale && (
                                <Check aria-hidden className="text-primary" />
                            )}
                        </DropdownMenuItem>
                    ))}

                <DropdownMenuItem
                    onSelect={toggle('theme')}
                    aria-expanded={open === 'theme'}
                >
                    <theme.icon aria-hidden />
                    <span className="flex-1">{t('nav.theme')}</span>
                    <span className="text-xs text-muted-foreground">
                        {t(theme.title)}
                    </span>
                    <ChevronDown
                        aria-hidden
                        className={cn(
                            'transition-transform',
                            open === 'theme' && 'rotate-180',
                        )}
                    />
                </DropdownMenuItem>
                {open === 'theme' &&
                    themes.map((item) => (
                        <DropdownMenuItem
                            key={item.mode}
                            className="pl-8"
                            onSelect={() => updateAppearance(item.mode)}
                        >
                            <item.icon aria-hidden />
                            <span className="flex-1">{t(item.title)}</span>
                            {item.mode === appearance && (
                                <Check aria-hidden className="text-primary" />
                            )}
                        </DropdownMenuItem>
                    ))}

                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={editProfile()}
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings aria-hidden />
                        {t('nav.settings')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        className="w-full cursor-pointer"
                        href={logout()}
                        as="button"
                        onClick={handleLogout}
                        data-test="logout-button"
                    >
                        <LogOut aria-hidden />
                        {t('nav.log_out')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
