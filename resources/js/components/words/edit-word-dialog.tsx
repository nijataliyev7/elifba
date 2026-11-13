import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import type { WordFormOptions } from '@/components/words/add-word-form';
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
import { update } from '@/routes/words';
import type { CustomWord } from '@/types';

type Props = {
    word: CustomWord;
    maxLength: number;
    /** How the page answers (`AddWordForm`). */
    options?: WordFormOptions;
};

/**
 * Edit one of the teacher's words: its text or its syllable split. The
 * word plays in every theme. Games already started keep their rounds.
 */
export function EditWordDialog({
    word,
    maxLength,
    options = { preserveScroll: true },
}: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={t('words.edit.button', { word: word.text })}
                >
                    <Pencil />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90svh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{t('words.edit.title')}</DialogTitle>
                    <DialogDescription>
                        {t('words.edit.description')}
                    </DialogDescription>
                </DialogHeader>

                <Form
                    {...update.form(word.id)}
                    options={options}
                    onSuccess={() => setOpen(false)}
                    className="grid gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <WordFields
                                id={`word-${word.id}`}
                                maxLength={maxLength}
                                errors={errors}
                                text={word.text}
                                syllables={word.override}
                            />

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        {t('common.cancel')}
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={processing}>
                                    {processing && <Spinner />}
                                    {t('common.save')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
