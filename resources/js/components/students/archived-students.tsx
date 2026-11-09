import { router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import Heading from '@/components/heading';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { destroy, restore } from '@/routes/classes/students';
import type { Avatar, Student } from '@/types';

type Props = {
    classId: string;
    /** Archived students, sorted by name on the server. */
    students: Student[];
    /** Every avatar with its picture, in the file's order. */
    avatars: Avatar[];
    retentionDays: number;
};

/**
 * Students who left the class: out of pickers and rankings, deleted
 * automatically later. Restore brings one back at the end of the order;
 * delete removes them for good, after a confirmation.
 */
export function ArchivedStudents({
    classId,
    students,
    avatars,
    retentionDays,
}: Props) {
    const { t } = useTranslation();

    const bringBack = (student: Student): void => {
        router.delete(restore.url({ class: classId, student: student.id }), {
            preserveScroll: true,
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ??
                        t('common.something_went_wrong'),
                ),
        });
    };

    return (
        <section className="space-y-4" aria-labelledby="archived-students">
            <div id="archived-students">
                <Heading
                    variant="small"
                    title={t('students.archived.title')}
                    description={t('students.archived.description', {
                        days: retentionDays,
                    })}
                />
            </div>
            <ul className="divide-y rounded-xl border">
                {students.map((student) => (
                    <li
                        key={student.id}
                        className="flex min-w-0 items-center gap-3 py-2 pr-2 pl-3"
                    >
                        <StudentAvatar
                            avatar={student.avatar}
                            avatars={avatars}
                            initial={student.display_name.charAt(0)}
                            className="opacity-60"
                        />
                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                            {student.display_name}
                        </span>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => bringBack(student)}
                        >
                            {t('common.restore')}
                        </Button>
                        <ConfirmDialog
                            trigger={
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    aria-label={t('students.delete.button', {
                                        name: student.display_name,
                                    })}
                                >
                                    <Trash2 />
                                </Button>
                            }
                            title={t('students.delete.title')}
                            description={t('students.delete.description', {
                                name: student.display_name,
                            })}
                            confirmLabel={t('common.delete')}
                            form={destroy.form({
                                class: classId,
                                student: student.id,
                            })}
                            destructive
                        />
                    </li>
                ))}
            </ul>
        </section>
    );
}
