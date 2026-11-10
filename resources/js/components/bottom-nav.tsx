import { Link, usePage } from '@inertiajs/react';
import { navAreas } from '@/components/app/nav-areas';
import { useTranslation } from '@/hooks/use-translation';
import { activeArea } from '@/lib/nav-active';
import { cn } from '@/lib/utils';

/**
 * The bottom tab bar between the teacher shell's areas, below `lg`
 * (docs/frontend.md §1). Each tab opens its area's first page; the open
 * area is marked. The shell pads its content by the bar's height and the
 * safe area (`AppSidebarLayout`), so nothing hides under the bar.
 */
export function BottomNav() {
    const { url } = usePage();
    const { t } = useTranslation();
    const current = activeArea(navAreas, url);

    return (
        <nav
            aria-label={t('nav.main')}
            className="fixed inset-x-0 bottom-0 z-30 border-t bg-background/95 pb-[env(safe-area-inset-bottom)] backdrop-blur supports-backdrop-filter:bg-background/80 lg:hidden"
        >
            <ul className="grid auto-cols-fr grid-flow-col">
                {navAreas.map((area) => {
                    const active = area === current;

                    return (
                        <li key={area.key} className="contents">
                            <Link
                                href={area.pages[0].href}
                                prefetch
                                aria-current={active ? 'page' : undefined}
                                data-test={`bottom-nav-${area.key}`}
                                className={cn(
                                    'flex h-16 min-w-0 flex-col items-center justify-center gap-1 px-1 text-[0.6875rem] leading-tight font-medium transition-colors',
                                    'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none focus-visible:ring-inset',
                                    active
                                        ? 'text-foreground'
                                        : 'text-muted-foreground hover:text-foreground',
                                )}
                            >
                                <span
                                    className={cn(
                                        'flex h-7 w-14 items-center justify-center rounded-full transition-colors',
                                        active &&
                                            'bg-primary text-primary-foreground',
                                    )}
                                >
                                    <area.icon
                                        aria-hidden
                                        className="size-5 shrink-0"
                                    />
                                </span>
                                <span
                                    className={cn(
                                        'max-w-full truncate',
                                        active && 'font-semibold',
                                    )}
                                >
                                    {t(area.title)}
                                </span>
                            </Link>
                        </li>
                    );
                })}
            </ul>
        </nav>
    );
}
