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
import type {
    BuiltInWord,
    CustomWord,
    LevelTotal,
    WordLimits,
    WordPack,
} from '@/types';

type Props = {
    /** The teacher's words, in the order they were added; they play in every pack. */
    customWords: CustomWord[];
    /** How many distinct items the teacher's words give each word and syllable level. */
    levelTotals: LevelTotal[];
    /** Every theme pack, in the server's order. */
    packs: WordPack[];
    /** The key of the pack whose built-in words are shown. */
    pack: string;
    builtInWords: BuiltInWord[];
    limits: WordLimits;
};

/**
 * The teacher's words (docs/architecture.md §3 "Teacher words", ADR 009):
 * their own words, which play in every theme, with add, edit and delete and
 * what each gives the word and syllable levels; then the built-in words of
 * one theme at a time with hide / show. Words, syllables and level titles
 * are shown exactly as the server sends them.
 */
export default function WordsIndex({
    customWords,
    levelTotals,
    packs,
    pack,
    builtInWords,
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
                            </>
                        )}
                    </Form>

                    {customWords.length > 0 && (
                        <div className="grid gap-2">
                            <p className="text-sm text-muted-foreground">
                                {t('words.custom.totals')}
                            </p>
                            <ul className="flex flex-wrap gap-2">
                                {levelTotals.map((level) => (
                                    <li
                                        key={level.title}
                                        className={cn(
                                            'inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm',
                                            level.count === 0 &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {level.title}
                                        <span className="font-semibold tabular-nums">
                                            {level.count}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

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
                                        <p className="truncate font-board text-lg">
                                            {word.text}
                                        </p>
                                        <p className="font-board text-sm text-muted-foreground">
                                            {word.syllables}
                                        </p>
                                        <ul
                                            aria-label={t(
                                                'words.custom.levels',
                                            )}
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
                                                    {level.unit ===
                                                        'syllable' && (
                                                        <span
                                                            lang="az"
                                                            className="truncate font-board font-medium"
                                                        >
                                                            {level.items.join(
                                                                ' · ',
                                                            )}
                                                        </span>
                                                    )}
                                                </li>
                                            ))}
                                        </ul>
                                        {word.built_in_in.length > 0 && (
                                            <p className="mt-1 text-sm text-muted-foreground">
                                                {t('words.custom.built_in_in', {
                                                    packs: word.built_in_in.join(
                                                        ', ',
                                                    ),
                                                })}
                                            </p>
                                        )}
                                    </div>
                                    <EditWordDialog
                                        word={word}
                                        maxLength={limits.max_length}
                                    />
                                    <ConfirmDialog
                                        trigger={
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                aria-label={t(
                                                    'words.delete.button',
                                                    {
                                                        word: word.text,
                                                    },
                                                )}
                                            >
                                                <Trash2 />
                                            </Button>
                                        }
                                        title={t('words.delete.title')}
                                        description={t(
                                            'words.delete.description',
                                            {
                                                word: word.text,
                                            },
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
