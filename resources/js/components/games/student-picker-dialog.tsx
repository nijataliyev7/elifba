import { UserPlus, X } from 'lucide-react';
import type { ReactNode } from 'react';
import { useId, useState } from 'react';
import { StudentGrid } from '@/components/games/student-grid';
import { AddStudentForm } from '@/components/students/add-student-form';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import type { BoardStudent } from '@/types';

type PickerProps = {
    title: string;
    description: string;
    students: BoardStudent[];
    /** Null: the whole class plays, and no one is credited. */
    studentId: string | null;
    accent: string;
    onChoose: (studentId: string | null) => void;
    /**
     * The class «Şagird əlavə et» adds to: a teacher board of a class game
     * only; null on a guest board (its bearer reaches no class).
     */
    addTo: string | null;
};

type Props = PickerProps & {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** The button that opens it, when it opens itself (the footer's picker). */
    trigger?: ReactNode;
    'data-test'?: string;
};

/** The board answers a student added here with only `students`, keeping its state. */
const BOARD_RELOAD = {
    preserveScroll: true,
    preserveState: true,
    only: ['students'],
};

/**
 * Who is at the board, to pick from (docs/architecture.md §4): «Kim
 * oynayır?» before «Başla» and the footer's «Kim lövhədədir?» are this one
 * dialog with their own title. On a teacher board its top-right corner has
 * a green «Şagird əlavə et» beside ✕, which opens one name field here
 * (`AddStudentForm`); the student added joins the grid unchosen, since a
 * choice in «Kim oynayır?» starts the game.
 */
export function StudentPickerDialog({
    open,
    onOpenChange,
    trigger,
    'data-test': dataTest,
    ...picker
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {trigger !== undefined && (
                <DialogTrigger asChild>{trigger}</DialogTrigger>
            )}
            <DialogContent
                showCloseButton={false}
                className="max-h-[90svh] overflow-y-auto sm:max-w-3xl"
                data-test={dataTest}
            >
                {/* Inside the content: the field closes with the dialog. */}
                <Picker {...picker} />
            </DialogContent>
        </Dialog>
    );
}

function Picker({
    title,
    description,
    students,
    studentId,
    accent,
    onChoose,
    addTo,
}: PickerProps) {
    const { t } = useTranslation();
    const [adding, setAdding] = useState(false);
    const formId = useId();

    return (
        <>
            <div className="flex items-start gap-3">
                <DialogHeader className="min-w-0 flex-1 text-left">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <div className="-mt-2 -mr-2 flex shrink-0 items-center gap-1">
                    {addTo !== null && (
                        <Button
                            type="button"
                            size="lg"
                            className="bg-green-700 text-white hover:bg-green-800 max-sm:size-11 max-sm:px-0"
                            aria-expanded={adding}
                            aria-controls={adding ? formId : undefined}
                            onClick={() => setAdding((shown) => !shown)}
                            data-test="add-student-open"
                        >
                            <UserPlus />
                            <span className="max-sm:sr-only">
                                {t('students.add_one.button')}
                            </span>
                        </Button>
                    )}
                    <DialogClose asChild>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-10 max-lg:size-11"
                            aria-label={t('common.close')}
                        >
                            <X />
                        </Button>
                    </DialogClose>
                </div>
            </div>
            {adding && addTo !== null && (
                <div id={formId}>
                    <AddStudentForm
                        classId={addTo}
                        options={BOARD_RELOAD}
                        onCancel={() => setAdding(false)}
                        className="rounded-xl border p-4"
                    />
                </div>
            )}
            <StudentGrid
                students={students}
                studentId={studentId}
                accent={accent}
                onChoose={onChoose}
            />
        </>
    );
}
