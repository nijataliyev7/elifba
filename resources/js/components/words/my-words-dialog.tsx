import { router } from '@inertiajs/react';
import { Play } from 'lucide-react';
import { useEffect, useId } from 'react';
import { AddWordForm } from '@/components/words/add-word-form';
import type { WordFormOptions } from '@/components/words/add-word-form';
import { CustomWordList } from '@/components/words/custom-word-list';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import type { MyWords } from '@/types';

/** The teacher's words on a game catalog page; null where it is not offered (a parent's catalog). */
export type CatalogWords = {
    /** `myWords`, an optional prop: undefined until «Sözlərim» first loads it. */
    list: MyWords | undefined;
    /** The page props a change to the words reloads with `myWords`: the catalog's options. */
    reload: string[];
};

type Props = {
    words: CatalogWords;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    /** «Bu sözlərlə oyna»: given when the dialog was opened for a game. */
    onPlay?: () => void;
};

/**
 * «Sözlərim» (docs/frontend.md rule 13; architecture §3 "Teacher words"):
 * the teacher's words without leaving the game catalog — the words page's
 * add form and list (`AddWordForm`, `CustomWordList`) in one dialog, opened
 * by «+» on a card or by «Söz əlavə et» in «Əlavə». It loads `myWords`
 * each time it opens; a word added, edited or deleted keeps the dialog and
 * the page's state and reloads `myWords` with the catalog's options, so
 * the list and the cards change at once.
 */
export function MyWordsDialog({ words, open, onOpenChange, onPlay }: Props) {
    const { t } = useTranslation();
    const id = useId();
    const { list, reload } = words;
    const options: WordFormOptions = {
        preserveScroll: true,
        preserveState: true,
        only: [...reload, 'myWords'],
    };

    useEffect(() => {
        if (open) {
            router.reload({ only: ['myWords'] });
        }
    }, [open]);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90svh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{t('words.mine.title')}</DialogTitle>
                    <DialogDescription>
                        {t('words.custom.description')}
                    </DialogDescription>
                </DialogHeader>

                {list === undefined ? (
                    <div className="grid place-items-center py-10">
                        <Spinner className="size-6" />
                    </div>
                ) : (
                    <div className="grid min-w-0 gap-4">
                        <AddWordForm
                            id={`${id}-new-word`}
                            limits={list.limits}
                            options={options}
                            autoFocus
                        />
                        <CustomWordList
                            words={list.words}
                            maxLength={list.limits.max_length}
                            options={options}
                        />
                    </div>
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="secondary">
                            {t('common.close')}
                        </Button>
                    </DialogClose>
                    {onPlay !== undefined && (
                        <Button
                            type="button"
                            disabled={
                                list === undefined || list.words.length === 0
                            }
                            onClick={onPlay}
                        >
                            <Play />
                            {t('words.mine.play')}
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
