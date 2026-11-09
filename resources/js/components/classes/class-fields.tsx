import { useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useTranslation } from '@/hooks/use-translation';

const GRADES = ['1', '2', '3', '4'];

type Props = {
    name?: string;
    grade?: number | null;
    errors: Partial<Record<string, string>>;
    autoFocus?: boolean;
};

/**
 * The class name and grade, for the create dialog and the class settings.
 * The grade is optional: no button pressed sends an empty value (null).
 */
export function ClassFields({
    name = '',
    grade = null,
    errors,
    autoFocus,
}: Props) {
    const { t } = useTranslation();
    const [selected, setSelected] = useState(
        grade === null ? '' : String(grade),
    );

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="class-name">{t('classes.fields.name')}</Label>
                <Input
                    id="class-name"
                    name="name"
                    defaultValue={name}
                    required
                    maxLength={40}
                    autoComplete="off"
                    autoFocus={autoFocus}
                    placeholder={t('classes.fields.name_placeholder')}
                    aria-invalid={errors.name ? true : undefined}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label id="class-grade-label">
                    {t('classes.fields.grade')}
                </Label>
                <input type="hidden" name="grade" value={selected} />
                <ToggleGroup
                    type="single"
                    variant="outline"
                    value={selected}
                    onValueChange={setSelected}
                    aria-labelledby="class-grade-label"
                    className="w-fit"
                >
                    {GRADES.map((value) => (
                        <ToggleGroupItem
                            key={value}
                            value={value}
                            className="min-w-11"
                            aria-label={t(`classes.grades.${value}`)}
                        >
                            {value}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                <p className="text-sm text-muted-foreground">
                    {t('classes.fields.grade_help')}
                </p>
                <InputError message={errors.grade} />
            </div>
        </>
    );
}
