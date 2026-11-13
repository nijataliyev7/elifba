import { Trash2 } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import type { WordFormOptions } from '@/components/words/add-word-form';
import { EditWordDialog } from '@/components/words/edit-word-dialog';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { destroy } from '@/routes/words';
import type { CustomWord } from '@/types';

type Props = {
    /** The teacher's words, in the order they were added. */
    words: CustomWord[];
    maxLength: number;
    /** How the page answers an edit or a delete (`AddWordForm`). */
    options?: WordFormOptions;
};

/**
 * The teacher's own words (docs/architecture.md §3 "Teacher words"), each
 * with its syllables, what it gives the word and syllable levels and the
 * packs that have it built in, and «Düzəlt» and «Sil» (which asks first);
 * or a dashed line when there are none. Words, syllables and level titles
 * are shown exactly as the server sends them. The words page and
 * «Sözlərim» use it alike.
 */
export function CustomWordList({ words, maxLength, options }: Props) {
    const { t } = useTranslation();

    if (words.length === 0) {
        return (
            <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                {t('words.custom.empty')}
            </p>
        );
    }

    return (
        <ul className="divide-y rounded-xl border">
            {words.map((word) => (
                <li key={word.id} className="flex items-center gap-3 p-3">
                    <div className="min-w-0 flex-1">
                        <p className="truncate font-board text-lg">
                            {word.text}
                        </p>
                        <p className="font-board text-sm text-muted-foreground">
                            {word.syllables}
                        </p>
                        <ul
                            aria-label={t('words.custom.levels')}
                            className="mt-2 flex flex-wrap gap-1.5"
                        >
                            {word.levels.map((level) => (
                                <li
                                    key={level.title}
                                    className="inline-flex max-w-full items-center gap-1.5 rounded-md bg-muted px-2 py-0.5 text-xs"
                                >
                                    <span className="text-muted-foreground">
                                        {level.title}
                                    </span>
                                    {level.unit === 'syllable' && (
                                        <span
                                            lang="az"
                                            className="truncate font-board font-medium"
                                        >
                                            {level.items.join(' · ')}
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                        {word.built_in_in.length > 0 && (
                            <p className="mt-1 text-sm text-muted-foreground">
                                {t('words.custom.built_in_in', {
                                    packs: word.built_in_in.join(', '),
                                })}
                            </p>
                        )}
                    </div>
                    <EditWordDialog
                        word={word}
                        maxLength={maxLength}
                        options={options}
                    />
                    <ConfirmDialog
                        trigger={
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={t('words.delete.button', {
                                    word: word.text,
                                })}
                            >
                                <Trash2 />
                            </Button>
                        }
                        title={t('words.delete.title')}
                        description={t('words.delete.description', {
                            word: word.text,
                        })}
                        confirmLabel={t('common.delete')}
                        form={destroy.form(word.id)}
                        options={options}
                        destructive
                    />
                </li>
            ))}
        </ul>
    );
}
