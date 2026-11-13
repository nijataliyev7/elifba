import { Head, Link } from '@inertiajs/react';
import { ImageOff } from 'lucide-react';
import Heading from '@/components/heading';
import { AddWordForm } from '@/components/words/add-word-form';
import { BuiltInWords } from '@/components/words/built-in-words';
import { CustomWordList } from '@/components/words/custom-word-list';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index } from '@/routes/words';
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
 * what each gives the word and syllable levels (`AddWordForm`,
 * `CustomWordList`, which «Sözlərim» on the game catalog shares); then the
 * built-in words of one theme at a time with hide / show. Words, syllables
 * and level titles are shown exactly as the server sends them.
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

                    <AddWordForm id="new-word" limits={limits} />

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

                    <CustomWordList
                        words={customWords}
                        maxLength={limits.max_length}
                    />
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
