import { Link, usePage } from '@inertiajs/react';
import { navAreas } from '@/components/app/nav-areas';
import { useTranslation } from '@/hooks/use-translation';
import { activeArea, activePage } from '@/lib/nav-active';
import { cn } from '@/lib/utils';

/**
 * The open area's pages as a pill strip under the header, below `lg`
 * (docs/frontend.md §1): the bottom bar says which area, the pills which
 * page inside it. Nothing for an area with a single page. The strip
 * scrolls sideways inside itself, never the screen.
 */
export function AreaPills() {
    const { url } = usePage();
    const { t } = useTranslation();
    const area = activeArea(navAreas, url);

    if (!area || area.pages.length < 2) {
        return null;
    }

    const page = activePage(area, url);

    return (
        <nav
            aria-label={t(area.title)}
            className="sticky top-14 z-20 border-b bg-background/95 backdrop-blur supports-backdrop-filter:bg-background/80 lg:hidden"
        >
            <div className="flex [scrollbar-width:none] gap-2 overflow-x-auto px-4 py-2.5 [&::-webkit-scrollbar]:hidden">
                {area.pages.map((item) => {
                    const active = item === page;

                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            prefetch
                            aria-current={active ? 'page' : undefined}
                            className={cn(
                                'inline-flex shrink-0 items-center gap-2 rounded-full border px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors',
                                'focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none',
                                active
                                    ? 'border-transparent bg-primary text-primary-foreground shadow-sm'
                                    : 'bg-card text-muted-foreground hover:bg-accent hover:text-foreground',
                            )}
                        >
                            <item.icon
                                aria-hidden
                                className="size-4 shrink-0"
                            />
                            {t(item.title)}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
