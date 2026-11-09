import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    /** Prefix for the inputs' ids, unique on the page. */
    id: string;
    maxLength: number;
    errors: Partial<Record<string, string>>;
    text?: string;
    /** The override as typed (`pom-i-dor`); empty lets the server split. */
    syllables?: string | null;
    autoFocus?: boolean;
};

/**
 * The word and its optional syllable split, as the teacher types them.
 * The server normalizes, splits at the hyphens and checks both
 * (`CustomWordData`); this only shows its messages.
 */
export function WordFields({
    id,
    maxLength,
    errors,
    text = '',
    syllables = null,
    autoFocus = false,
}: Props) {
    const { t } = useTranslation();
    const syllablesError =
        errors.syllables ??
        Object.entries(errors).find(([key]) =>
            key.startsWith('syllables.'),
        )?.[1];

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${id}-text`}>{t('words.fields.text')}</Label>
                <Input
                    id={`${id}-text`}
                    name="text"
                    defaultValue={text}
                    required
                    maxLength={maxLength}
                    autoFocus={autoFocus}
                    autoComplete="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    className="font-board text-base"
                    aria-invalid={errors.text ? true : undefined}
                    aria-describedby={`${id}-text-help`}
                />
                <p
                    id={`${id}-text-help`}
                    className="text-sm text-muted-foreground"
                >
                    {t('words.fields.text_help')}
                </p>
                <InputError message={errors.text} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${id}-syllables`}>
                    {t('words.fields.syllables')}
                </Label>
                <Input
                    id={`${id}-syllables`}
                    name="syllables"
                    defaultValue={syllables ?? ''}
                    maxLength={maxLength * 2}
                    autoComplete="off"
                    autoCapitalize="none"
                    spellCheck={false}
                    className="font-board text-base"
                    aria-invalid={syllablesError ? true : undefined}
                    aria-describedby={`${id}-syllables-help`}
                />
                <p
                    id={`${id}-syllables-help`}
                    className="text-sm text-muted-foreground"
                >
                    {t('words.fields.syllables_help')}
                </p>
                <InputError message={syllablesError} />
            </div>
        </>
    );
}
