import { Form, router } from '@inertiajs/react';
import { Archive, Pencil } from 'lucide-react';
import { useState } from 'react';
import { StudentAvatar } from '@/components/students/student-avatar';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { archive, update } from '@/routes/classes/students';
import type { Avatar, Student } from '@/types';

type Props = {
    classId: string;
    student: Student;
    /** Every avatar with its picture, in the file's order. */
    avatars: Avatar[];
};

/**
 * Edit a student's display name and avatar, or move them to the archive
 * (a child who left the class). Nothing else is stored about a child.
 */
export function EditStudentDialog({ classId, student, avatars }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [avatar, setAvatar] = useState(student.avatar);
    const [archiving, setArchiving] = useState(false);
    const args = { class: classId, student: student.id };

    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                setOpen(next);
                setAvatar(student.avatar);
            }}
        >
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('students.edit.button', {
                        name: student.display_name,
                    })}
                >
                    <Pencil />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('students.edit.title')}</DialogTitle>
                    <DialogDescription>
                        {t('students.edit.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...update.form(args)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor={`student-${student.id}-name`}>
                                    {t('students.fields.name')}
                                </Label>
                                <Input
                                    id={`student-${student.id}-name`}
                                    name="display_name"
                                    defaultValue={student.display_name}
                                    required
                                    maxLength={40}
                                    autoComplete="off"
                                    spellCheck={false}
                                    aria-invalid={
                                        errors.display_name ? true : undefined
                                    }
                                />
                                <p className="text-sm text-muted-foreground">
                                    {t('students.fields.name_help')}
                                </p>
                                <InputError message={errors.display_name} />
                            </div>

                            <fieldset className="grid gap-2">
                                <legend className="mb-2 text-sm font-medium">
                                    {t('students.fields.avatar')}
                                </legend>
                                <input
                                    type="hidden"
                                    name="avatar"
                                    value={avatar}
                                />
                                <div className="flex flex-wrap gap-2">
                                    {avatars.map(({ key }, index) => (
                                        <button
                                            key={key}
                                            type="button"
                                            aria-pressed={key === avatar}
                                            aria-label={t(
                                                'students.fields.avatar_option',
                                                { number: index + 1 },
                                            )}
                                            onClick={() => setAvatar(key)}
                                            className={cn(
                                                'rounded-full p-0.5 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                                key === avatar
                                                    ? 'ring-2 ring-primary'
                                                    : 'ring-1 ring-transparent hover:ring-border',
                                            )}
                                        >
                                            <StudentAvatar
                                                avatar={key}
                                                avatars={avatars}
                                            />
                                        </button>
                                    ))}
                                </div>
                                <InputError message={errors.avatar} />
                            </fieldset>

                            <DialogFooter className="gap-2 sm:justify-between">
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={processing || archiving}
                                    onClick={() =>
                                        router.post(
                                            archive.url(args),
                                            {},
                                            {
                                                preserveScroll: true,
                                                onStart: () =>
                                                    setArchiving(true),
                                                onFinish: () =>
                                                    setArchiving(false),
                                                onSuccess: () => setOpen(false),
                                            },
                                        )
                                    }
                                >
                                    {archiving ? <Spinner /> : <Archive />}
                                    {t('students.edit.archive')}
                                </Button>
                                <div className="flex flex-col-reverse gap-2 sm:flex-row">
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            {t('common.cancel')}
                                        </Button>
                                    </DialogClose>
                                    <Button type="submit" disabled={processing}>
                                        {processing && <Spinner />}
                                        {t('common.save')}
                                    </Button>
                                </div>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
