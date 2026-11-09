import { ChevronRight, Users } from 'lucide-react';
import { useState } from 'react';
import { StudentGrid, studentAvatars } from '@/components/games/student-grid';
import { StudentAvatar } from '@/components/students/student-avatar';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import type { BoardStudent } from '@/types';

type Props = {
    students: BoardStudent[];
    /** Null: the whole class plays, and no one is credited. */
    studentId: string | null;
    accent: string;
    disabled: boolean;
    onChoose: (studentId: string | null) => void;
    onNext: () => void;
};

/**
 * Who is at the board (docs/architecture.md §4, "Board without a phone"):
 * the student credited with the rounds solved now, «Bütün sinif» (no
 * points), "next" in class order, and a grid of the class to pick from.
 */
export function BoardStudents({
    students,
    studentId,
    accent,
    disabled,
    onChoose,
    onNext,
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const avatars = studentAvatars(students);
    const current = students.find((student) => student.id === studentId);

    const choose = (id: string | null) => {
        onChoose(id);
        setOpen(false);
    };

    return (
        <div className="flex min-w-0 items-center gap-3">
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <button
                        type="button"
                        disabled={disabled}
                        className="flex min-w-0 items-center gap-3 rounded-2xl border-2 bg-white px-3 py-2 text-left shadow-xs transition-colors hover:bg-neutral-50 disabled:opacity-60"
                        style={{ borderColor: accent }}
                        aria-label={t('games.board.choose_student')}
                    >
                        {current === undefined ? (
                            <span className="flex size-14 shrink-0 items-center justify-center rounded-full bg-neutral-100">
                                <Users className="size-8" aria-hidden />
                            </span>
                        ) : (
                            <StudentAvatar
                                avatar={current.avatar.key}
                                avatars={avatars}
                                initial={current.display_name.slice(0, 1)}
                                className="size-14 text-xl"
                            />
                        )}
                        <span className="min-w-0">
                            <span className="block text-sm font-medium text-neutral-500">
                                {t('games.board.at_board')}
                            </span>
                            <span className="block max-w-[28ch] truncate font-board text-2xl font-bold">
                                {current?.display_name ??
                                    t('games.board.whole_class')}
                            </span>
                        </span>
                    </button>
                </DialogTrigger>
                <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-3xl">
                    <DialogHeader>
                        <DialogTitle>
                            {t('games.board.choose_student')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('games.board.choose_student_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <StudentGrid
                        students={students}
                        studentId={studentId}
                        accent={accent}
                        onChoose={choose}
                    />
                </DialogContent>
            </Dialog>

            {students.length > 0 && (
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    className="h-14 text-base"
                    disabled={disabled}
                    onClick={onNext}
                >
                    {t('games.board.next_student')}
                    <ChevronRight />
                </Button>
            )}
        </div>
    );
}
