import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { RankingPeriod } from '@/types';
import type { RouteDefinition, RouteQueryOptions } from '@/wayfinder';

type Props = {
    classId: string;
    /** The page the links stay on: the rankings or the podium (same query). */
    route: (
        classId: string,
        options: RouteQueryOptions,
    ) => RouteDefinition<'get'>;
    period: RankingPeriod;
    /** The chosen date (`YYYY-MM-DD`, from the server). */
    date: string;
    /** "9 noyabr 2026" or "9–15 noyabr 2026", formatted by the server. */
    label: string;
    current: boolean;
    previous: string;
    next: string | null;
};

const PERIODS: RankingPeriod[] = ['day', 'week'];

/**
 * The day / week switch and the window's navigation: the window before,
 * the window after (until today) and back to today. Every date comes from
 * the server; this only puts them in the query. Without a date the server
 * shows the window that holds today.
 */
export function PeriodNav({
    classId,
    route,
    period,
    date,
    label,
    current,
    previous,
    next,
}: Props) {
    const { t } = useTranslation();

    const href = (to: RankingPeriod, on: string | null) =>
        route(classId, {
            query: on === null ? { period: to } : { period: to, date: on },
        });

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <nav
                    aria-label={t('rankings.period.label')}
                    className="inline-flex rounded-md border p-0.5"
                >
                    {PERIODS.map((each) => (
                        <Link
                            key={each}
                            href={href(each, current ? null : date)}
                            preserveScroll
                            aria-current={each === period ? 'page' : undefined}
                            className={cn(
                                'inline-flex h-8 min-w-20 items-center justify-center rounded-[5px] px-3 text-sm font-medium transition-colors',
                                each === period
                                    ? 'bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                            )}
                        >
                            {t(`rankings.period.${each}`)}
                        </Link>
                    ))}
                </nav>

                {current ? (
                    <Button variant="outline" size="sm" disabled>
                        {t(`rankings.current.${period}`)}
                    </Button>
                ) : (
                    <Button variant="outline" size="sm" asChild>
                        <Link href={href(period, null)} preserveScroll>
                            {t(`rankings.current.${period}`)}
                        </Link>
                    </Button>
                )}
            </div>

            <div className="flex items-center gap-2 rounded-xl border p-1.5">
                <Button variant="ghost" size="icon" asChild>
                    <Link
                        href={href(period, previous)}
                        preserveScroll
                        aria-label={t(`rankings.previous.${period}`)}
                    >
                        <ChevronLeft />
                    </Link>
                </Button>

                <p
                    className="min-w-0 flex-1 text-center font-medium text-balance"
                    aria-live="polite"
                >
                    {label}
                </p>

                {next === null ? (
                    <Button
                        variant="ghost"
                        size="icon"
                        disabled
                        aria-label={t(`rankings.next.${period}`)}
                    >
                        <ChevronRight />
                    </Button>
                ) : (
                    <Button variant="ghost" size="icon" asChild>
                        <Link
                            href={href(period, next)}
                            preserveScroll
                            aria-label={t(`rankings.next.${period}`)}
                        >
                            <ChevronRight />
                        </Link>
                    </Button>
                )}
            </div>
        </div>
    );
}
