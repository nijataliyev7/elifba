import { Link } from '@inertiajs/react';
import { ChevronDown, Lock } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useState } from 'react';
import InputError from '@/components/input-error';
import { AddWordDialog } from '@/components/words/add-word-dialog';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { index as wordsIndex } from '@/routes/words';
import type { Letter, SetupRefusal } from '@/types';

type Props = {
    /** The values beside «Əlavə», joined. */
    summary: string;
    /** The letter case, or null at a level that does not read. */
    letterCase: 'upper' | 'lower' | null;
    letterCases: ('upper' | 'lower')[];
    onLetterCase: (letterCase: 'upper' | 'lower') => void;
    letters: Letter[];
    /** The chosen letters at the letter level, or null elsewhere. */
    asked: Set<string> | null;
    onAsked: (asked: Set<string>) => void;
    /** `option.own` of the game at its chosen level. */
    own: SetupRefusal | null;
    ownWords: boolean;
    onOwnWords: (on: boolean) => void;
    /** The teacher area: the words page and adding a word are offered when own words cannot start. */
    teacher: boolean;
    wordMaxLength: number;
    errors: Record<string, string | undefined>;
};

/** A sub-question's label inside «Əlavə» or a game page. */
export function SetupLabel({ children }: { children: ReactNode }) {
    return (
        <legend className="mb-2 text-sm font-medium text-muted-foreground">
            {children}
        </legend>
    );
}

/**
 * A row button: chosen is the `default` look, the others `outline`; at
 * least 44px, with `aria-pressed`. A locked one is dashed with a lock,
 * `aria-disabled` but focusable, and never chosen.
 */
export function SetupRowButton({
    chosen,
    locked = false,
    children,
    className,
    onClick,
    lang,
}: {
    chosen: boolean;
    locked?: boolean;
    children: ReactNode;
    className?: string;
    onClick?: () => void;
    lang?: string;
}) {
    return (
        <button
            type="button"
            lang={lang}
            aria-pressed={chosen}
            aria-disabled={locked || undefined}
            onClick={onClick}
            className={cn(
                'inline-flex min-h-11 min-w-11 items-center justify-center gap-1.5 rounded-md border px-3 py-1.5 text-sm font-medium transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                chosen
                    ? 'border-primary bg-primary text-primary-foreground'
                    : locked
                      ? 'cursor-default border-dashed bg-transparent text-muted-foreground'
                      : 'bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:border-input dark:bg-input/30 dark:hover:bg-input/50',
                className,
            )}
        >
            {children}
            {locked && <Lock aria-hidden className="size-3.5 shrink-0" />}
        </button>
    );
}

/** The fields of «Əlavə»: it opens by itself when the server refuses one of them. */
const FIELDS = ['letter_case', 'letters', 'own_words'];

/**
 * «Əlavə» on a game page (docs/frontend.md rule 13): closed by default,
 * its values beside the button. Inside: the letter case at a reading
 * level, the letters at the letter level and, where the server offers it,
 * whether to play only the owner's own words. A game whose unit does not
 * read (pictures, numbers) has no «Əlavə». The round count is the board's,
 * under «Başla» (architecture §4 "Round count").
 */
export function SetupExtras({
    summary,
    letterCase,
    letterCases,
    onLetterCase,
    letters,
    asked,
    onAsked,
    own,
    ownWords,
    onOwnWords,
    teacher,
    wordMaxLength,
    errors,
}: Props) {
    const { t } = useTranslation();
    const id = useId();
    const [open, setOpen] = useState(false);
    const lettersError =
        errors.letters ??
        Object.entries(errors).find(([key]) => key.startsWith('letters.'))?.[1];
    const refused =
        FIELDS.some((field) => errors[field] !== undefined) ||
        lettersError !== undefined;

    useEffect(() => {
        if (refused) {
            setOpen(true);
        }
    }, [refused]);

    const ownShown = own !== null && (own.startable || teacher);

    if (letterCase === null && !ownShown) {
        return null;
    }

    const toggle = (letter: string) => {
        const next = new Set(asked);

        if (next.has(letter)) {
            next.delete(letter);
        } else {
            next.add(letter);
        }

        onAsked(next);
    };

    return (
        <section className="grid gap-4">
            <button
                type="button"
                aria-expanded={open}
                aria-controls={id}
                onClick={() => setOpen(!open)}
                className="flex min-h-13 w-full min-w-0 items-center gap-3 rounded-xl border bg-card px-4 py-2 text-left transition-colors outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
            >
                <span className="flex min-w-0 flex-1 flex-col">
                    <span className="font-semibold">
                        {t('games.setup.extra')}
                    </span>
                    <span className="truncate text-sm text-muted-foreground">
                        {summary}
                    </span>
                </span>
                <ChevronDown
                    aria-hidden
                    className={cn(
                        'size-5 shrink-0 text-muted-foreground transition-transform',
                        open && 'rotate-180',
                    )}
                />
            </button>

            <div id={id} hidden={!open} className="grid gap-6 px-1">
                {letterCase !== null && (
                    <fieldset className="grid gap-1">
                        <SetupLabel>{t('games.setup.letter_case')}</SetupLabel>
                        <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                            {letterCases.map((item) => (
                                <SetupRowButton
                                    key={item}
                                    chosen={item === letterCase}
                                    className="flex-col gap-0 py-2"
                                    onClick={() => onLetterCase(item)}
                                >
                                    <span
                                        lang="az"
                                        className="font-board text-lg leading-tight tracking-wider"
                                    >
                                        {letters
                                            .slice(0, 3)
                                            .map((letter) => letter[item])
                                            .join(' ')}
                                    </span>
                                    <span>
                                        {t(`games.setup.letter_cases.${item}`)}
                                    </span>
                                </SetupRowButton>
                            ))}
                        </div>
                        <InputError message={errors.letter_case} />
                    </fieldset>
                )}

                {asked !== null && letterCase !== null && (
                    <fieldset className="grid gap-1">
                        <SetupLabel>{t('games.setup.letters')}</SetupLabel>
                        <div className="flex flex-wrap gap-2">
                            <SetupRowButton
                                chosen={asked.size === 0}
                                onClick={() => onAsked(new Set())}
                            >
                                {t('games.setup.letters_all', {
                                    count: letters.length,
                                })}
                            </SetupRowButton>
                            {letters.map((letter) => (
                                <SetupRowButton
                                    key={letter.lower}
                                    lang="az"
                                    chosen={asked.has(letter.lower)}
                                    className="font-board text-lg"
                                    onClick={() => toggle(letter.lower)}
                                >
                                    {letter[letterCase]}
                                </SetupRowButton>
                            ))}
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('games.setup.letters_hint')}
                        </p>
                        <InputError message={lettersError} />
                    </fieldset>
                )}

                {own !== null && ownShown && (
                    <fieldset className="grid gap-1">
                        <SetupLabel>{t('games.setup.words')}</SetupLabel>
                        {own.startable ? (
                            <div className="flex flex-wrap gap-2">
                                {[true, false].map((item) => (
                                    <SetupRowButton
                                        key={String(item)}
                                        chosen={item === ownWords}
                                        onClick={() => onOwnWords(item)}
                                    >
                                        {t(
                                            item
                                                ? 'games.setup.words_own'
                                                : 'games.setup.words_all',
                                        )}
                                    </SetupRowButton>
                                ))}
                            </div>
                        ) : own.code === 'no_own_words' ? (
                            <p className="text-sm text-muted-foreground">
                                {own.reason}{' '}
                                <Link
                                    href={wordsIndex()}
                                    className="font-medium text-foreground underline underline-offset-4"
                                >
                                    {t('nav.words')}
                                </Link>
                            </p>
                        ) : (
                            <div className="grid justify-items-start gap-2">
                                <p className="text-sm text-muted-foreground">
                                    {own.reason}
                                </p>
                                <AddWordDialog maxLength={wordMaxLength} />
                            </div>
                        )}
                        <InputError message={errors.own_words} />
                    </fieldset>
                )}
            </div>
        </section>
    );
}
