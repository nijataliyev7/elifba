import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { WordFields } from '@/components/words/word-fields';
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
import { store } from '@/routes/words';

type Props = {
    maxLength: number;
};

/**
 * Add one of the teacher's words without leaving the page
 * (docs/architecture.md §3 "Teacher words"): the game setup offers it when
 * «Yalnız mənim sözlərim» lacks words. `AddCustomWord` redirects back, and
 * the page keeps its state, so the setup's options come back with the word.
 */
export function AddWordDialog({ maxLength }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button type="button" variant="outline" size="sm">
                    <Plus />
                    {t('words.add.title')}
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('words.add.title')}</DialogTitle>
                    <DialogDescription>
                        {t('words.custom.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...store.form()}
                    options={{ preserveScroll: true, preserveState: true }}
                    resetOnSuccess
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <WordFields
                                id="setup-new-word"
                                maxLength={maxLength}
                                errors={errors}
                                autoFocus
                            />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        {t('common.close')}
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing ? <Spinner /> : <Plus />}
                                    {t('words.add.button')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
