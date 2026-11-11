import { Star } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { ChildStars as ChildStarsProps } from '@/types';

type Props = {
    stars: ChildStarsProps;
    retentionDays: number;
};

/**
 * One child's own stars (architecture §5 "A child's stars", ADR 011):
 * today, this week and in all, then the last seven Baku days, oldest first,
 * each a row with the server's date label, a bar and the number. No place,
 * no "best", nothing beside a sibling. "In all" means the games still
 * kept, and the section says so.
 */
export function ChildStars({ stars, retentionDays }: Props) {
    const { t } = useTranslation();
    const most = Math.max(0, ...stars.days.map((day) => day.stars));

    return (
        <section className="space-y-4" aria-labelledby="child-stars">
            <h2 id="child-stars" className="text-lg font-semibold">
                {t('children.stars.title')}
            </h2>

            <dl className="grid grid-cols-3 gap-2 sm:gap-3">
                {(
                    [
                        ['children.stars.today_label', stars.today],
                        ['children.stars.week', stars.week],
                        ['children.stars.total', stars.total],
                    ] as const
                ).map(([label, value]) => (
                    <div
                        key={label}
                        className="flex min-w-0 flex-col items-center gap-1 rounded-2xl border bg-card p-3 text-center sm:p-4"
                    >
                        <dt className="truncate text-sm text-muted-foreground">
                            {t(label)}
                        </dt>
                        <dd className="flex items-center gap-1.5 text-3xl font-semibold tabular-nums">
                            <Star
                                aria-hidden
                                className="size-6 fill-amber-400 text-amber-500"
                            />
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>

            <div className="space-y-2">
                <h3 className="text-sm font-medium">
                    {t('children.stars.days')}
                </h3>
                <ol className="space-y-1 rounded-2xl border p-3 sm:p-4">
                    {stars.days.map((day, index) => {
                        const today = index === stars.days.length - 1;

                        return (
                            <li
                                key={day.date}
                                className="grid grid-cols-[minmax(0,9rem)_1fr_auto] items-center gap-3 py-1 text-sm"
                            >
                                <span
                                    className={cn(
                                        'truncate',
                                        today
                                            ? 'font-medium'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {day.label}
                                </span>
                                <span
                                    aria-hidden
                                    className="h-2.5 overflow-hidden rounded-[4px] bg-muted"
                                >
                                    {day.stars > 0 && (
                                        <span
                                            className="block h-full rounded-[4px] bg-amber-400 dark:bg-amber-500"
                                            style={{
                                                width: `${(day.stars / most) * 100}%`,
                                            }}
                                        />
                                    )}
                                </span>
                                <span className="min-w-[4ch] text-right font-medium tabular-nums">
                                    <span className="sr-only">
                                        {t('children.stars.count', {
                                            count: day.stars,
                                        })}
                                    </span>
                                    <span aria-hidden>{day.stars}</span>
                                </span>
                            </li>
                        );
                    })}
                </ol>
                <p className="text-sm text-muted-foreground">
                    {t('children.stars.kept', { days: retentionDays })}
                </p>
            </div>
        </section>
    );
}
