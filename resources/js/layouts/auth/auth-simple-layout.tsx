import { Link } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageSwitcher } from '@/components/language-switcher';
import { credits, home, privacy } from '@/routes';
import { useTranslation } from '@/hooks/use-translation';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { t } = useTranslation();

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            <div className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        <Link
                            href={home()}
                            className="flex flex-col items-center gap-2 font-medium"
                        >
                            <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-9 fill-current text-[var(--foreground)] dark:text-white" />
                            </div>
                            <span className="sr-only">{title}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                </div>
            </div>

            <footer className="flex flex-wrap items-center justify-center gap-4">
                <LanguageSwitcher />
                <Link
                    href={privacy()}
                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    {t('nav.privacy')}
                </Link>
                <Link
                    href={credits()}
                    className="text-sm text-muted-foreground underline-offset-4 hover:underline"
                >
                    {t('nav.credits')}
                </Link>
            </footer>
        </div>
    );
}
