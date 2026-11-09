import { Form } from '@inertiajs/react';
import { Archive, ArchiveRestore, Trash2 } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import { ClassFields } from '@/components/classes/class-fields';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { archive, destroy, restore, update } from '@/routes/classes';
import type { SchoolClass } from '@/types';

/**
 * Class settings: rename, change the grade, archive or restore, delete.
 * Archiving and deleting ask first; deleting takes the students with it.
 */
export function ClassSettings({
    schoolClass,
    retentionDays,
}: {
    schoolClass: SchoolClass;
    retentionDays: number;
}) {
    const { t } = useTranslation();

    return (
        <section className="space-y-6" aria-labelledby="class-settings">
            <div id="class-settings">
                <Heading
                    variant="small"
                    title={t('classes.settings.title')}
                    description={t('classes.settings.description')}
                />
            </div>

            <Form
                {...update.form(schoolClass.id)}
                options={{ preserveScroll: true }}
                className="grid max-w-md gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <ClassFields
                            key={`${schoolClass.name}|${schoolClass.grade}`}
                            name={schoolClass.name}
                            grade={schoolClass.grade}
                            errors={errors}
                        />
                        <div>
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('common.save')}
                            </Button>
                        </div>
                    </>
                )}
            </Form>

            <div className="flex flex-wrap gap-2 border-t pt-6">
                {schoolClass.archived ? (
                    <Form
                        {...restore.form(schoolClass.id)}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button
                                type="submit"
                                variant="outline"
                                disabled={processing}
                            >
                                <ArchiveRestore />
                                {t('classes.restore.button')}
                            </Button>
                        )}
                    </Form>
                ) : (
                    <ConfirmDialog
                        trigger={
                            <Button variant="outline">
                                <Archive />
                                {t('classes.archive.button')}
                            </Button>
                        }
                        title={t('classes.archive.title')}
                        description={t('classes.archive.description', {
                            days: retentionDays,
                        })}
                        confirmLabel={t('classes.archive.button')}
                        form={archive.form(schoolClass.id)}
                    />
                )}

                <ConfirmDialog
                    trigger={
                        <Button variant="destructive">
                            <Trash2 />
                            {t('classes.delete.button')}
                        </Button>
                    }
                    title={t('classes.delete.title')}
                    description={t('classes.delete.description', {
                        name: schoolClass.name,
                    })}
                    confirmLabel={t('classes.delete.button')}
                    form={destroy.form(schoolClass.id)}
                    destructive
                />
            </div>
        </section>
    );
}
