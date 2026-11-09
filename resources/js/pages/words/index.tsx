import { Form, Head, Link } from '@inertiajs/react';
import { ImageOff, Plus, Trash2 } from 'lucide-react';
import { ConfirmDialog } from '@/components/app/confirm-dialog';
import Heading from '@/components/heading';
import { BuiltInWords } from '@/components/words/built-in-words';
import { EditWordDialog } from '@/components/words/edit-word-dialog';
import { WordFields } from '@/components/words/word-fields';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { destroy, index, store } from '@/routes/words';
import type { BuiltInWord, CustomWord, WordLimits, WordPack } from '@/types';

type Props = {
    /** Every theme pack, in the server's order. */
    packs: WordPack[];
    /** The key of the pack shown. */
    pack: string;
    builtInWords: BuiltInWord[];
    /** The teacher's words in this pack, in the order they were added. */
    customWords: CustomWord[];
    limits: WordLimits;
};

/**
 * The teacher's words, one theme pack at a time (docs/architecture.md §3
 * "Teacher words"): their own words with add, edit and delete, and the
 * built-in words with hide / show. Words and syllables are shown exactly
 * as the server sends them.
 */
export default function WordsIndex({
    packs,
    pack,
    builtInWords,
    customWords,
    limits,
}: Props) {
    const { t } = useTranslation();
    const hiddenCount = builtInWords.filter((word) => word.hidden).length;
    const full = limits.count >= limits.max_per_teacher;

    return (
        <>
            <Head title={t('words.title')} />

            <div className="mx-auto flex w-full max-w-4xl flex-col gap-8 p-4 md:p-6">
                <div className="min-w-0">
                    <h1 className="text-2xl font-semibold tracking-tight">
                        {t('words.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('words.description')}
                    </p>
                </div>

                <nav
                    aria-label={t('words.pack')}
                    className="flex flex-wrap gap-2"
                >
                    {packs.map((item) => (
                        <Link
                            key={item.key}
                            href={index({ query: { pack: item.key } })}
                            preserveScroll
                            aria-current={
                                item.key === pack ? 'page' : undefined
                            }
                            className={cn(
                                'inline-flex h-9 max-w-full items-center truncate rounded-full border px-4 text-sm font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none',
                                item.key === pack
                                    ? 'border-primary bg-primary text-primary-foreground'
                                    : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground',
                            )}
                        >
                            {item.title}
                        </Link>
                    ))}
                </nav>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title={t('words.custom.title')}
                        description={t('words.custom.description')}
                    />
                    <p className="flex items-start gap-2 text-sm text-muted-foreground">
                        <ImageOff
                            aria-hidden
                            className="mt-0.5 size-4 shrink-0"
                        />
                        {t('words.custom.no_picture', {
                            game: t('games.mechanics.build_word'),
                        })}
                    </p>

                    <Form
                        key={pack}
                        {...store.form()}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="grid gap-4 rounded-xl border p-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <h3 className="font-medium">
                                    {t('words.add.title')}
                                </h3>
                                <input type="hidden" name="pack" value={pack} />
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <WordFields
                                        id="new-word"
                                        maxLength={limits.max_length}
                                        errors={errors}
                                    />
                                </div>
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <p className="text-sm text-muted-foreground">
                                        {t('words.custom.limit', {
                                            count: limits.count,
                                            max: limits.max_per_teacher,
                                        })}
                                    </p>
                                    <Button
                                        type="submit"
                                        disabled={processing || full}
                                    >
                                        {processing ? <Spinner /> : <Plus />}
                                        {t('words.add.button')}
                                    </Button>
                                </div>
                                {errors.pack && (
                                    <p className="text-sm text-destructive">
                                        {errors.pack}
                                    </p>
                                )}
                            </>
                        )}
                    </Form>

                    {customWords.length === 0 ? (
                        <p className="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
                            {t('words.custom.empty')}
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {customWords.map((word) => (
                                <li
                                    key={word.id}
                                    className="flex items-center gap-3 p-3"
                                >
                                    <div className="min-w-0 flex-1">
                                        <p
                                            className={cn(
                                                'truncate font-board text-lg',
                                                !word.played &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {word.text}
                                        </p>
                                        <p className="font-board text-sm text-muted-foreground">
                                            {word.syllables}
                                        </p>
                                        {!word.played && (
                                            <p className="text-sm text-muted-foreground">
                                                {t('words.custom.not_played')}
                                            </p>
                                        )}
                                    </div>
                                    <EditWordDialog
                                        word={word}
                                        pack={pack}
                                        packs={packs}
                                        maxLength={limits.max_length}
                                    />
                                    <ConfirmDialog
                                        trigger={
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t(
                                                    'words.delete.button',
                                                    { word: word.text },
                                                )}
                                            >
                                                <Trash2 />
                                            </Button>
                                        }
                                        title={t('words.delete.title')}
                                        description={t(
                                            'words.delete.description',
                                            { word: word.text },
                                        )}
                                        confirmLabel={t('common.delete')}
                                        form={destroy.form(word.id)}
                                        destructive
                                    />
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className="space-y-4">
                    <Heading
                        variant="small"
                        title={t('words.built_in.title')}
                        description={t('words.built_in.description')}
                    />
                    {hiddenCount > 0 && (
                        <p className="text-sm text-muted-foreground">
                            {t('words.built_in.hidden_count', {
                                count: hiddenCount,
                            })}
                        </p>
                    )}
                    <BuiltInWords pack={pack} words={builtInWords} />
                </section>
            </div>
        </>
    );
}

WordsIndex.layout = {
    breadcrumbs: [{ title: 'nav.words', href: index() }],
};
