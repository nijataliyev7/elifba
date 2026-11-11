import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';

type Props = {
    id: string;
    defaultValue?: string;
    error?: string;
    autoFocus?: boolean;
};

/** A child's first name (ADR 013): no surname, no initial; the server checks it. */
export function ChildNameField({ id, defaultValue, error, autoFocus }: Props) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{t('children.fields.name')}</Label>
            <Input
                id={id}
                name="display_name"
                defaultValue={defaultValue}
                required
                maxLength={40}
                autoComplete="off"
                autoCapitalize="words"
                spellCheck={false}
                autoFocus={autoFocus}
                className="h-11 text-base"
                aria-invalid={error ? true : undefined}
                aria-describedby={`${id}-hint`}
            />
            <p id={`${id}-hint`} className="text-sm text-muted-foreground">
                {t('children.fields.name_hint')}
            </p>
            <InputError message={error} />
        </div>
    );
}
