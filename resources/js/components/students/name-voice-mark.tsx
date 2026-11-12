import { Volume2 } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

/**
 * Beside a student's or a child's name that the board says aloud (ADR 016
 * decision 10, `has_voice`): a small speaker, «Lövhə bu adı səslə deyir»
 * for screen readers and on hover. Shown only while the account's names
 * and praise are on; a name without a clip gets no mark of its own.
 */
export function NameVoiceMark({ className }: { className?: string }) {
    const { t } = useTranslation();
    const label = t('students.voice.has');

    return (
        <span
            className="inline-flex shrink-0 text-muted-foreground"
            title={label}
            data-test="name-voice"
        >
            <Volume2 aria-hidden className={cn('size-4', className)} />
            <span className="sr-only">{label}</span>
        </span>
    );
}
