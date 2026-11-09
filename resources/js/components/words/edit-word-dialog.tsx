import { Form } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { update } from '@/routes/words';
import type { CustomWord, WordPack } from '@/types';

type Props = {
    word: CustomWord;
    pack: string;
    packs: WordPack[];
    maxLength: number;
};

/**
 * Edit one of the teacher's words: its text, its syllable split, or the
 * theme it belongs to. Games already started keep their rounds.
 */
export function EditWordDialog({ word, pack, packs, maxLength }: Props) {
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
                    options={{ preserveScroll: true }}
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

                            <div className="grid gap-2">
                                <Label htmlFor={`word-${word.id}-pack`}>
                                    {t('words.pack')}
                                </Label>
                                <Select name="pack" defaultValue={pack}>
                                    <SelectTrigger
                                        id={`word-${word.id}-pack`}
                                        className="w-full"
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {packs.map((item) => (
                                            <SelectItem
                                                key={item.key}
                                                value={item.key}
                                            >
                                                {item.title}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.pack} />
                            </div>

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
