import { router } from '@inertiajs/react';
import { useState } from 'react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { Toggle } from '@/components/ui/toggle';
import { useTranslation } from '@/hooks/use-translation';
import { update } from '@/routes/classes/learned-letters';
import type { Letter } from '@/types';

type Props = {
    classId: string;
    /** The 32 letters in alphabet order, both cases made by the server. */
    alphabet: Letter[];
    /** Lowercase letters the class has learned. */
    learned: string[];
};

/**
 * The 32 learned-letter toggles with "all" and "none" (docs/architecture.md
 * §3). Every game level uses only content made of the switched-on letters.
 */
export function LearnedLetters({ classId, alphabet, learned }: Props) {
    const { t } = useTranslation();
    const [on, setOn] = useState<Set<string>>(() => new Set(learned));
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | undefined>();

    const dirty =
        on.size !== learned.length || learned.some((letter) => !on.has(letter));

    const toggle = (letter: string, pressed: boolean): void => {
        setOn((current) => {
            const next = new Set(current);

            if (pressed) {
                next.add(letter);
            } else {
                next.delete(letter);
            }

            return next;
        });
    };

    const save = (): void => {
        router.put(
            update.url(classId),
            {
                letters: alphabet
                    .map((letter) => letter.lower)
                    .filter((letter) => on.has(letter)),
            },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => setError(undefined),
                onError: (errors) => setError(Object.values(errors)[0]),
            },
        );
    };

    return (
        <section className="space-y-4" aria-labelledby="learned-letters">
            <div id="learned-letters">
                <Heading
                    variant="small"
                    title={t('classes.letters.title')}
                    description={t('classes.letters.description')}
                />
            </div>

            <div className="flex flex-wrap items-center justify-between gap-2">
                <p className="text-sm text-muted-foreground" aria-live="polite">
                    {t('classes.letters.count', {
                        count: on.size,
                        total: alphabet.length,
                    })}
                </p>
                <div className="flex gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            setOn(
                                new Set(alphabet.map((letter) => letter.lower)),
                            )
                        }
                    >
                        {t('classes.letters.all')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() => setOn(new Set())}
                    >
                        {t('classes.letters.none')}
                    </Button>
                </div>
            </div>

            <div
                role="group"
                aria-label={t('classes.letters.title')}
                className="flex flex-wrap gap-2"
            >
                {alphabet.map((letter) => (
                    <Toggle
                        key={letter.lower}
                        variant="outline"
                        pressed={on.has(letter.lower)}
                        onPressedChange={(pressed) =>
                            toggle(letter.lower, pressed)
                        }
                        lang="az"
                        className="h-11 min-w-14 text-base data-[state=on]:border-primary data-[state=on]:bg-primary data-[state=on]:text-primary-foreground"
                    >
                        {letter.upper} {letter.lower}
                    </Toggle>
                ))}
            </div>

            <InputError message={error} />

            <Button
                type="button"
                onClick={save}
                disabled={!dirty || saving}
                data-test="save-letters"
            >
                {saving && <Spinner />}
                {t('classes.letters.save')}
            </Button>
        </section>
    );
}
