import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Medal, Users } from 'lucide-react';
import { PeriodNav } from '@/components/rankings/period-nav';
import { RankingList } from '@/components/rankings/ranking-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, podium, rankings, show } from '@/routes/classes';
import type { RankingProps } from '@/types';

/**
 * A class's daily or weekly ranking (docs/architecture.md §5), only on the
 * teacher's own pages. The server derives it, sorts it, labels the places
 * and formats the dates; the page shows it and links to the windows the
 * server names.
 */
export default function RankingShow({
    schoolClass,
    period,
    date,
    label,
    current,
    previous,
    next,
    students,
    avatars,
}: RankingProps) {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            { title: 'nav.classes', href: index() },
            {
                title: 'classes.show.breadcrumb',
                label: schoolClass.name,
                href: show(schoolClass.id),
            },
            { title: 'rankings.title', href: rankings(schoolClass.id) },
        ],
    });

    return (
        <>
            <Head title={`${t('rankings.title')} · ${schoolClass.name}`} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <div className="flex min-w-0 flex-wrap items-center gap-2">
                            <h1 className="truncate text-2xl font-semibold tracking-tight">
                                {t('rankings.title')}
                            </h1>
                            <span className="truncate text-2xl text-muted-foreground">
                                {schoolClass.name}
                            </span>
                            {schoolClass.archived && (
                                <Badge variant="secondary">
                                    {t('classes.archived.badge')}
                                </Badge>
                            )}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('rankings.description')}
                        </p>
                    </div>
                    {students.some((student) => student.place !== null) && (
                        <Button variant="outline" asChild>
                            <Link
                                href={podium(schoolClass.id, {
                                    query: current
                                        ? { period }
                                        : { period, date },
                                })}
                                data-test="podium"
                            >
                                <Medal />
                                {t('podium.title')}
                            </Link>
                        </Button>
                    )}
                </div>

                <PeriodNav
                    classId={schoolClass.id}
                    route={rankings}
                    period={period}
                    date={date}
                    label={label}
                    current={current}
                    previous={previous}
                    next={next}
                />

                {students.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed p-8 text-center">
                        <Users
                            aria-hidden
                            className="size-10 text-muted-foreground"
                        />
                        <p className="font-medium">
                            {t('students.empty_title')}
                        </p>
                        <Button asChild>
                            <Link href={show(schoolClass.id)}>
                                {t('students.paste.button')}
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <RankingList
                        period={period}
                        students={students}
                        avatars={avatars}
                    />
                )}
            </div>
        </>
    );
}
