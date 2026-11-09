import { Users } from 'lucide-react';
import type { ReactNode } from 'react';
import { StudentAvatar } from '@/components/students/student-avatar';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Avatar, BoardStudent } from '@/types';

type Props = {
    students: BoardStudent[];
    /** Null: the whole class plays, and no one is credited. */
    studentId: string | null;
    /** The pack's accent on the board; the app's primary colour elsewhere. */
    accent?: string;
    onChoose: (studentId: string | null) => void;
};

/**
 * Who is at the board, to pick from (docs/architecture.md §4): «Bütün
 * sinif» and the class's active students in class order. The board's
 * picker and the phone's remote show the same grid.
 */
export function StudentGrid({ students, studentId, accent, onChoose }: Props) {
    const { t } = useTranslation();
    const avatars = studentAvatars(students);

    return (
        <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
            <ChoiceButton
                chosen={studentId === null}
                accent={accent}
                onClick={() => onChoose(null)}
            >
                <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-muted">
                    <Users className="size-7" aria-hidden />
                </span>
                <span className="min-w-0 truncate">
                    {t('games.board.whole_class')}
                </span>
            </ChoiceButton>
            {students.map((student) => (
                <ChoiceButton
                    key={student.id}
                    chosen={student.id === studentId}
                    accent={accent}
                    onClick={() => onChoose(student.id)}
                >
                    <StudentAvatar
                        avatar={student.avatar.key}
                        avatars={avatars}
                        initial={student.display_name.slice(0, 1)}
                        className="size-12"
                    />
                    <span className="min-w-0 truncate font-board text-lg">
                        {student.display_name}
                    </span>
                </ChoiceButton>
            ))}
        </div>
    );
}

/** The avatars of these students that have a picture, for `StudentAvatar`. */
export function studentAvatars(students: BoardStudent[]): Avatar[] {
    return students.flatMap((student) =>
        student.avatar.url === null
            ? []
            : [{ key: student.avatar.key, url: student.avatar.url }],
    );
}

function ChoiceButton({
    chosen,
    accent,
    onClick,
    children,
}: {
    chosen: boolean;
    accent?: string;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={chosen}
            className={cn(
                'flex min-h-16 min-w-0 items-center gap-2 rounded-xl border-2 p-2 text-left transition-colors hover:bg-accent',
                !chosen && 'border-transparent bg-muted/50',
                chosen && accent === undefined && 'border-primary',
            )}
            style={
                chosen && accent !== undefined
                    ? { borderColor: accent }
                    : undefined
            }
        >
            {children}
        </button>
    );
}
