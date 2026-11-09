import { Form, Head, Link } from '@inertiajs/react';
import { ChevronRight, School } from 'lucide-react';
import { CreateClassDialog } from '@/components/classes/create-class-dialog';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index, restore, show } from '@/routes/classes';
import type { ClassSummary } from '@/types';

type Props = {
    /** Active classes, sorted by name (Azerbaijani order) on the server. */
    classes: ClassSummary[];
    archivedClasses: ClassSummary[];
    retentionDays: number;
};

export default function ClassesIndex({
    classes,
    archivedClasses,
    retentionDays,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('classes.index.title')} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold tracking-tight">
                            {t('classes.index.title')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {t('classes.index.description')}
                        </p>
                    </div>
                    {classes.length > 0 && <CreateClassDialog />}
                </div>

                {classes.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed p-8 text-center">
                        <School
                            aria-hidden
                            className="size-10 text-muted-foreground"
                        />
                        <div className="space-y-1">
                            <p className="font-medium">
                                {t('classes.index.empty_title')}
                            </p>
                            <p className="text-sm text-muted-foreground">
                                {t('classes.index.empty_description')}
                            </p>
                        </div>
                        <CreateClassDialog />
                    </div>
                ) : (
                    <ul className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {classes.map((schoolClass) => (
                            <li key={schoolClass.id}>
                                <Link
                                    href={show(schoolClass.id)}
                                    prefetch
                                    className="flex items-center gap-3 rounded-xl border bg-card p-4 transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate text-lg font-semibold">
                                            {schoolClass.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            <ClassFacts
                                                schoolClass={schoolClass}
                                            />
                                        </p>
                                    </div>
                                    <ChevronRight
                                        aria-hidden
                                        className="size-5 shrink-0 text-muted-foreground"
                                    />
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                {archivedClasses.length > 0 && (
                    <section className="space-y-4">
                        <Heading
                            variant="small"
                            title={t('classes.archived.title')}
                            description={t('classes.archived.description', {
                                days: retentionDays,
                            })}
                        />
                        <ul className="divide-y rounded-xl border">
                            {archivedClasses.map((schoolClass) => (
                                <li
                                    key={schoolClass.id}
                                    className="flex items-center gap-3 p-3"
                                >
                                    <Link
                                        href={show(schoolClass.id)}
                                        className="min-w-0 flex-1 rounded-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    >
                                        <span className="block truncate font-medium">
                                            {schoolClass.name}
                                        </span>
                                        <span className="block text-sm text-muted-foreground">
                                            <ClassFacts
                                                schoolClass={schoolClass}
                                            />
                                        </span>
                                    </Link>
                                    <Form
                                        {...restore.form(schoolClass.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <Button
                                                type="submit"
                                                variant="outline"
                                                size="sm"
                                                disabled={processing}
                                            >
                                                {t('common.restore')}
                                            </Button>
                                        )}
                                    </Form>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </div>
        </>
    );
}

function ClassFacts({ schoolClass }: { schoolClass: ClassSummary }) {
    const { t } = useTranslation();
    const students = t('classes.students_count', {
        count: schoolClass.students_count,
    });

    return schoolClass.grade === null ? (
        students
    ) : (
        <>
            {t(`classes.grades.${schoolClass.grade}`)}
            <span aria-hidden> · </span>
            {students}
        </>
    );
}

ClassesIndex.layout = {
    breadcrumbs: [{ title: 'nav.classes', href: index() }],
};
