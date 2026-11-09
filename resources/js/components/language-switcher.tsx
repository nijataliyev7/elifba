import { router, usePage } from '@inertiajs/react';
import { Languages } from 'lucide-react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { update } from '@/routes/locale';

/**
 * The az/en switch. The server keeps the choice in the session and, for a
 * logged-in teacher, on the account (`users.locale`).
 */
export function LanguageSwitcher({ className }: { className?: string }) {
    const { locale, locales } = usePage().props;
    const { t } = useTranslation();

    const choose = (code: string): void => {
        if (code === locale) {
            return;
        }

        router.put(update.url(), { locale: code }, { preserveScroll: true });
    };

    return (
        <div
            role="group"
            aria-label={t('nav.language')}
            className={cn(
                'inline-flex items-center gap-1 rounded-lg bg-neutral-100 p-1 text-sm dark:bg-neutral-800',
                className,
            )}
        >
            <Languages
                aria-hidden
                className="mx-1 size-4 text-muted-foreground"
            />
            {Object.entries(locales).map(([code, name]) => (
                <button
                    key={code}
                    type="button"
                    lang={code}
                    aria-pressed={code === locale}
                    data-test={`locale-${code}`}
                    onClick={() => choose(code)}
                    className={cn(
                        'cursor-pointer rounded-md px-2.5 py-1 transition-colors',
                        code === locale
                            ? 'bg-white shadow-xs dark:bg-neutral-700 dark:text-neutral-100'
                            : 'text-neutral-500 hover:bg-neutral-200/60 hover:text-black dark:text-neutral-400 dark:hover:bg-neutral-700/60',
                    )}
                >
                    {name}
                </button>
            ))}
        </div>
    );
}
