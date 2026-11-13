import { Form } from '@inertiajs/react';
import { UserPlus } from 'lucide-react';
import type { ComponentProps } from 'react';
import { useId } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/classes/students';

type Props = {
    classId: string;
    /** How the page answers: the board reloads only `students` and keeps its state. */
    options?: ComponentProps<typeof Form>['options'];
    onCancel: () => void;
    className?: string;
};

/**
 * One student by one name (docs/architecture.md §4 "A student added on the
 * board"): the class page's own `POST /classes/{class}/students` with
 * `names: [name]`, so the display name rule and its messages are the paste
 * flow's; the route answers back to the page that asked. The server's
 * error shows under the field; on success the field empties for the next
 * name. The name goes only into the POST body, never a URL.
 */
export function AddStudentForm({
    classId,
    options = { preserveScroll: true },
    onCancel,
    className,
}: Props) {
    const { t } = useTranslation();
    const id = useId();

    return (
        <Form
            {...store.form(classId)}
            options={options}
            resetOnSuccess
            className={className}
            data-test="add-student"
        >
            {({ processing, errors }) => {
                const error = errors['names.0'] ?? errors.names;

                return (
                    <div className="grid gap-2">
                        <Label htmlFor={id}>
                            {t('students.add_one.label')}
                        </Label>
                        <div className="flex flex-wrap gap-2">
                            <Input
                                id={id}
                                name="names[]"
                                maxLength={40}
                                required
                                autoFocus
                                autoComplete="off"
                                spellCheck={false}
                                placeholder={t(
                                    'students.fields.name_placeholder',
                                )}
                                aria-invalid={error ? true : undefined}
                                aria-describedby={`${id}-help`}
                                className="h-11 min-w-0 flex-1 basis-48 font-board text-lg md:text-lg"
                            />
                            <div className="flex gap-2">
                                <Button
                                    type="submit"
                                    className="h-11"
                                    disabled={processing}
                                >
                                    {processing ? <Spinner /> : <UserPlus />}
                                    {t('students.add_one.submit')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="h-11"
                                    onClick={onCancel}
                                >
                                    {t('common.cancel')}
                                </Button>
                            </div>
                        </div>
                        <InputError message={error} />
                        <p
                            id={`${id}-help`}
                            className="text-sm text-muted-foreground"
                        >
                            {t('students.fields.name_help')}
                        </p>
                    </div>
                );
            }}
        </Form>
    );
}
