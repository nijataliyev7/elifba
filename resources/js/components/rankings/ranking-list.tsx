import { Trophy } from 'lucide-react';
import { StudentAvatar } from '@/components/students/student-avatar';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Avatar, RankedStudent, RankingPeriod } from '@/types';

type Props = {
    period: RankingPeriod;
    /** Ranked students first, then those with 0 points, as the server sorted them. */
    students: RankedStudent[];
    avatars: Avatar[];
};

/**
 * A class ranking (docs/architecture.md §5): students with points in dense
 * places — tied students share the place and its label — and after them,
 * without a place, the students with no points yet. Places 1–3 (the
 * podium) stand out; nothing marks a last place.
 */
export function RankingList({ period, students, avatars }: Props) {
    const { t } = useTranslation();
    const ranked = students.filter((student) => student.place !== null);
    const unranked = students.filter((student) => student.place === null);

    return (
        <div className="flex flex-col gap-8">
            {ranked.length === 0 ? (
                <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-8 text-center">
                    <Trophy
                        aria-hidden
                        className="size-10 text-muted-foreground"
                    />
                    <p className="text-sm text-muted-foreground">
                        {t(`rankings.empty.${period}`)}
                    </p>
                </div>
            ) : (
                <ol className="divide-y rounded-xl border">
                    {ranked.map((student) => (
                        <li
                            key={student.id}
                            className="flex min-w-0 items-center gap-3 py-2 pr-3 pl-2"
                        >
                            <span
                                aria-label={t('rankings.place', {
                                    place: student.place_label ?? '',
                                })}
                                className={cn(
                                    'inline-flex h-7 min-w-12 shrink-0 items-center justify-center rounded-full px-2 text-sm font-semibold tabular-nums',
                                    (student.place ?? 0) <= 3
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {student.place_label}
                            </span>
                            <StudentAvatar
                                avatar={student.avatar}
                                avatars={avatars}
                                initial={student.display_name.charAt(0)}
                            />
                            <span className="min-w-0 flex-1 truncate font-medium">
                                {student.display_name}
                            </span>
                            <span className="shrink-0 text-sm font-semibold tabular-nums">
                                {t('rankings.points', {
                                    count: student.points,
                                })}
                            </span>
                        </li>
                    ))}
                </ol>
            )}

            {unranked.length > 0 && (
                <section className="space-y-3" aria-labelledby="no-points">
                    <h2
                        id="no-points"
                        className="text-sm font-medium text-muted-foreground"
                    >
                        {t('rankings.no_points')}
                    </h2>
                    <ul className="flex flex-wrap gap-2">
                        {unranked.map((student) => (
                            <li
                                key={student.id}
                                className="flex max-w-full min-w-0 items-center gap-2 rounded-full border py-1 pr-3 pl-1"
                            >
                                <StudentAvatar
                                    avatar={student.avatar}
                                    avatars={avatars}
                                    initial={student.display_name.charAt(0)}
                                    className="size-7"
                                />
                                <span className="min-w-0 truncate text-sm">
                                    {student.display_name}
                                </span>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
