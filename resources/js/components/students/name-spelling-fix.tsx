import { router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type Props = {
    /** The name as Elifba's name list spells it (`voice_spelling`). */
    spelling: string;
    /** The page's own update route of this student or child. */
    url: string;
    avatar: string;
    /** 44px targets, on the parent's pages (docs/frontend.md rule 12). */
    large?: boolean;
};

/**
 * Under a name that Elifba's name list spells otherwise — typed without the
 * Azerbaijani letters or in other casing, «Lale» for «Lalə» (ADR 016
 * decision 10): one muted line with the list's spelling and «Düzəlt», which
 * saves the name so through the page's own update route. The board says the
 * name either way; it shows it as stored, to children learning to read, so
 * the fix is offered, never made without this tap.
 */
export function NameSpellingFix({
    spelling,
    url,
    avatar,
    large = false,
}: Props) {
    const { t } = useTranslation();
    const [saving, setSaving] = useState(false);

    const fix = (): void => {
        router.patch(
            url,
            { display_name: spelling, avatar },
            {
                preserveScroll: true,
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onError: (errors) =>
                    toast.error(
                        Object.values(errors)[0] ??
                            t('common.something_went_wrong'),
                    ),
            },
        );
    };

    return (
        <span
            className="flex flex-wrap items-center gap-x-2 text-sm text-muted-foreground"
            data-test="name-spelling"
        >
            <span>{t('students.voice.spelling', { name: spelling })}</span>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className={cn(large && 'h-11 px-4')}
                disabled={saving}
                onClick={fix}
                aria-label={t('students.voice.fix_label', { name: spelling })}
            >
                {t('students.voice.fix')}
            </Button>
        </span>
    );
}
