import { router } from '@inertiajs/react';
import { Eye, EyeOff } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { destroy as show, store as hide } from '@/routes/words/hidden';
import type { BuiltInWord } from '@/types';

type Props = {
    pack: string;
    words: BuiltInWord[];
};

/**
 * The pack's built-in words, each with a hide / show toggle for the
 * teacher's own games. The word goes in the request body, never the URL.
 */
export function BuiltInWords({ pack, words }: Props) {
    const { t } = useTranslation();
    const [pending, setPending] = useState<string | null>(null);

    const toggle = (word: BuiltInWord) => {
        const route = word.hidden ? show() : hide();

        router.visit(route.url, {
            method: route.method,
            data: { pack, word: word.text },
            preserveScroll: true,
            onStart: () => setPending(word.text),
            onFinish: () => setPending(null),
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ??
                        t('common.something_went_wrong'),
                ),
        });
    };

    return (
        <ul className="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
            {words.map((word) => (
                <li
                    key={word.text}
                    className={cn(
                        'flex min-w-0 items-center gap-3 rounded-lg border p-3',
                        word.hidden && 'border-dashed bg-muted/40',
                    )}
                >
                    <div className="min-w-0 flex-1">
                        <p
                            className={cn(
                                'truncate font-board text-lg',
                                word.hidden &&
                                    'text-muted-foreground line-through',
                            )}
                        >
                            {word.text}
                        </p>
                        <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                            <span className="font-board">{word.syllables}</span>
                            {word.hidden && (
                                <Badge variant="secondary">
                                    {t('words.built_in.hidden')}
                                </Badge>
                            )}
                        </p>
                    </div>
                    <Button
                        type="button"
                        variant={word.hidden ? 'outline' : 'ghost'}
                        size="sm"
                        aria-label={t(
                            word.hidden
                                ? 'words.built_in.show_label'
                                : 'words.built_in.hide_label',
                            { word: word.text },
                        )}
                        disabled={pending !== null}
                        onClick={() => toggle(word)}
                    >
                        {pending === word.text ? (
                            <Spinner />
                        ) : word.hidden ? (
                            <Eye />
                        ) : (
                            <EyeOff />
                        )}
                        {t(
                            word.hidden
                                ? 'words.built_in.show'
                                : 'words.built_in.hide',
                        )}
                    </Button>
                </li>
            ))}
        </ul>
    );
}
