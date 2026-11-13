import { Form } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import type { ComponentProps } from 'react';
import { WordFields } from '@/components/words/word-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { store } from '@/routes/words';
import type { WordLimits } from '@/types';

/**
 * How a page answers a change to the teacher's words: the words page
 * reloads (`preserveScroll`), «Sözlərim» on the game catalog keeps its
 * state and reloads only `myWords` and the catalog's options.
 */
export type WordFormOptions = ComponentProps<typeof Form>['options'];

type Props = {
    /** Prefix for the inputs' ids, unique on the page. */
    id: string;
    limits: WordLimits;
    options?: WordFormOptions;
    autoFocus?: boolean;
};

/**
 * Add one of the teacher's words (docs/architecture.md §3 "Teacher
 * words"): the word and its optional split, how many the teacher has of the
 * most, and «Əlavə et», disabled at the most. `AddCustomWord` redirects
 * back and the fields empty for the next word. The words page and
 * «Sözlərim» use it alike.
 */
export function AddWordForm({
    id,
    limits,
    options = { preserveScroll: true },
    autoFocus = false,
}: Props) {
    const { t } = useTranslation();
    const full = limits.count >= limits.max_per_teacher;

    return (
        <Form
            {...store.form()}
            options={options}
            resetOnSuccess
            className="grid gap-4 rounded-xl border p-4"
        >
            {({ processing, errors }) => (
                <>
                    <h3 className="font-medium">{t('words.add.title')}</h3>
                    <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                        <WordFields
                            id={id}
                            maxLength={limits.max_length}
                            errors={errors}
                            autoFocus={autoFocus}
                        />
                    </div>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <p className="text-sm text-muted-foreground">
                            {t('words.custom.limit', {
                                count: limits.count,
                                max: limits.max_per_teacher,
                            })}
                        </p>
                        <Button type="submit" disabled={processing || full}>
                            {processing ? <Spinner /> : <Plus />}
                            {t('words.add.button')}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
