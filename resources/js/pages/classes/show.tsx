import { Head, Link, setLayoutProps } from '@inertiajs/react';
import { Medal, Play, Trophy, Users } from 'lucide-react';
import { ClassSettings } from '@/components/classes/class-settings';
import { LearnedLetters } from '@/components/classes/learned-letters';
import Heading from '@/components/heading';
import { ArchivedStudents } from '@/components/students/archived-students';
import { PasteNamesDialog } from '@/components/students/paste-names-dialog';
import { StudentList } from '@/components/students/student-list';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import { index, podium, rankings, show } from '@/routes/classes';
import { create as createGame } from '@/routes/classes/games';
import { update as updateLetters } from '@/routes/classes/learned-letters';
import type {
    ActiveStudent,
    Avatar,
    Letter,
    SchoolClass,
    Student,
} from '@/types';

type Props = {
    schoolClass: SchoolClass;
    /** Active students in the class's order. */
    students: ActiveStudent[];
    /** Archived students, sorted by name. */
    archivedStudents: Student[];
    alphabet: Letter[];
    /** Every avatar with its picture's URL, in the file's order. */
    avatars: Avatar[];
    retentionDays: number;
    limits: { paste_length: number; names: number };
};

export default function ClassShow({
    schoolClass,
    students,
    archivedStudents,
    alphabet,
    avatars,
    retentionDays,
    limits,
}: Props) {
    const { t } = useTranslation();

    setLayoutProps({
        breadcrumbs: [
            { title: 'nav.classes', href: index() },
            {
                title: 'classes.show.breadcrumb',
                label: schoolClass.name,
                href: show(schoolClass.id),
            },
        ],
    });

    return (
        <>
            <Head title={schoolClass.name} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-10 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="min-w-0 space-y-1">
                        <h1 className="truncate text-2xl font-semibold tracking-tight">
                            {schoolClass.name}
                        </h1>
                        <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            {schoolClass.grade !== null && (
                                <span>
                                    {t(`classes.grades.${schoolClass.grade}`)}
                                </span>
                            )}
                            <span>
                                {t('classes.students_count', {
                                    count: students.length,
                                })}
                            </span>
                            {schoolClass.archived && (
                                <Badge variant="secondary">
                                    {t('classes.archived.badge')}
                                </Badge>
                            )}
                        </div>
                    </div>
                    <div className="flex w-full flex-wrap gap-2 sm:w-auto">
                        {!schoolClass.archived && (
                            <Button asChild className="w-full sm:w-auto">
                                <Link
                                    href={createGame(schoolClass.id)}
                                    data-test="start-game"
                                >
                                    <Play />
                                    {t('classes.show.start_game')}
                                </Link>
                            </Button>
                        )}
                        {students.length > 0 && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={rankings(schoolClass.id)}
                                    data-test="rankings"
                                >
                                    <Trophy />
                                    {t('classes.show.rankings')}
                                </Link>
                            </Button>
                        )}
                        {students.length > 0 && (
                            <Button variant="outline" asChild>
                                <Link
                                    href={podium(schoolClass.id)}
                                    data-test="podium"
                                >
                                    <Medal />
                                    {t('podium.title')}
                                </Link>
                            </Button>
                        )}
                        {students.length > 0 && (
                            <PasteNamesDialog
                                classId={schoolClass.id}
                                limits={limits}
                                variant={
                                    schoolClass.archived ? 'default' : 'outline'
                                }
                            />
                        )}
                    </div>
                </div>

                {schoolClass.archived && (
                    <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                        {t('classes.archived.notice', { days: retentionDays })}
                    </p>
                )}

                <section className="space-y-4" aria-labelledby="students">
                    <div id="students">
                        <Heading
                            variant="small"
                            title={t('students.title')}
                            description={t('students.description')}
                        />
                    </div>

                    {students.length === 0 ? (
                        <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed p-8 text-center">
                            <Users
                                aria-hidden
                                className="size-10 text-muted-foreground"
                            />
                            <div className="space-y-1">
                                <p className="font-medium">
                                    {t('students.empty_title')}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t('students.empty_description')}
                                </p>
                            </div>
                            <PasteNamesDialog
                                classId={schoolClass.id}
                                limits={limits}
                                size="lg"
                            />
                        </div>
                    ) : (
                        <StudentList
                            classId={schoolClass.id}
                            students={students}
                            avatars={avatars}
                        />
                    )}
                </section>

                {archivedStudents.length > 0 && (
                    <ArchivedStudents
                        classId={schoolClass.id}
                        students={archivedStudents}
                        avatars={avatars}
                        retentionDays={retentionDays}
                    />
                )}

                <Separator />

                <LearnedLetters
                    key={schoolClass.learned_letters.join('')}
                    url={updateLetters.url(schoolClass.id)}
                    alphabet={alphabet}
                    learned={schoolClass.learned_letters}
                />

                <Separator />

                <ClassSettings
                    schoolClass={schoolClass}
                    retentionDays={retentionDays}
                />
            </div>
        </>
    );
}
