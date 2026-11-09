import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { ClassFields } from '@/components/classes/class-fields';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/classes';

/** "New class": name and grade; the server opens the new class's page. */
export function CreateClassDialog({ label }: { label?: string }) {
    const { t } = useTranslation();

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button data-test="create-class">
                    <Plus />
                    {label ?? t('classes.create.button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{t('classes.create.title')}</DialogTitle>
                    <DialogDescription>
                        {t('classes.create.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form {...store.form()} className="grid gap-6">
                    {({ processing, errors }) => (
                        <>
                            <ClassFields errors={errors} autoFocus />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        {t('common.cancel')}
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('classes.create.submit')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
