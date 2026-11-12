import { router } from '@inertiajs/react';
import { ArrowDown, ArrowUp } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { EditStudentDialog } from '@/components/students/edit-student-dialog';
import { NameSpellingFix } from '@/components/students/name-spelling-fix';
import { NameVoiceMark } from '@/components/students/name-voice-mark';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { order, update } from '@/routes/classes/students';
import type { ActiveStudent, Avatar } from '@/types';

type Props = {
    classId: string;
    /** Active students in the class's order (turns, lists). */
    students: ActiveStudent[];
    /** Every avatar with its picture, in the file's order. */
    avatars: Avatar[];
};

/**
 * The class's active students in their order. Up and down move a student
 * one place and save the whole order at once (`ReorderStudents`). A name
 * the board says aloud carries a small speaker (ADR 016 decision 10), and
 * one the list spells otherwise («Lale» → «Lalə») a one-tap fix; when a
 * name has no clip, one line under the list says the board only shows
 * names off Elifba's name list. None of them while the account says no
 * name.
 */
export function StudentList({ classId, students, avatars }: Props) {
    const { t } = useTranslation();
    const [saving, setSaving] = useState(false);

    const move = (from: number, to: number): void => {
        const ids = students.map((student) => student.id);
        const [moved] = ids.splice(from, 1);
        ids.splice(to, 0, moved);

        router.put(
            order.url(classId),
            { student_ids: ids },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            t('common.something_went_wrong'),
                    ),
            },
        );
    };

    const silent = students.some((student) => student.has_voice === false);

    return (
        <div className="space-y-2">
            <ol className="divide-y rounded-xl border" aria-busy={saving}>
                {students.map((student, index) => (
                    <li
                        key={student.id}
                        className="flex min-w-0 items-center gap-3 py-2 pr-2 pl-3"
                    >
                        <span className="w-6 shrink-0 text-right text-sm text-muted-foreground tabular-nums">
                            {index + 1}
                        </span>
                        <StudentAvatar
                            avatar={student.avatar}
                            avatars={avatars}
                            initial={student.display_name.charAt(0)}
                        />
                        <span className="flex min-w-0 flex-1 flex-col gap-1">
                            <span className="flex min-w-0 items-center gap-1.5">
                                <span className="min-w-0 truncate font-medium">
                                    {student.display_name}
                                </span>
                                {student.has_voice === true && (
                                    <NameVoiceMark />
                                )}
                            </span>
                            {student.voice_spelling !== null && (
                                <NameSpellingFix
                                    spelling={student.voice_spelling}
                                    url={update.url({
                                        class: classId,
                                        student: student.id,
                                    })}
                                    avatar={student.avatar}
                                />
                            )}
                        </span>
                        <div className="flex shrink-0 items-center">
                            <Button
                                variant="ghost"
                                size="icon"
                                disabled={saving || index === 0}
                                onClick={() => move(index, index - 1)}
                                aria-label={t('students.move_up', {
                                    name: student.display_name,
                                })}
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                variant="ghost"
                                size="icon"
                                disabled={
                                    saving || index === students.length - 1
                                }
                                onClick={() => move(index, index + 1)}
                                aria-label={t('students.move_down', {
                                    name: student.display_name,
                                })}
                            >
                                <ArrowDown />
                            </Button>
                            <EditStudentDialog
                                classId={classId}
                                student={student}
                                avatars={avatars}
                            />
                        </div>
                    </li>
                ))}
            </ol>
            {silent && (
                <p className="text-sm text-muted-foreground">
                    {t('students.voice.missing')}
                </p>
            )}
        </div>
    );
}
