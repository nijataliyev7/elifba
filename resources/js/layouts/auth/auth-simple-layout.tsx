import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { InstallBanner } from '@/components/install-banner';
import { LanguageSwitcher } from '@/components/language-switcher';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { credits, home, privacy } from '@/routes';
import type { AuthLayoutProps } from '@/types';

/**
 * Every `auth/*` page (frontend.md rule 1 "Auth pages"). On `lg+` a quiet
 * brand side (the logo, the welcome page's promise, a few of the logo's
 * balloons) sits next to the form; on a phone there is one column: the logo
 * and the language switch, then the page, then the footer.
 */
export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;
    const { t } = useTranslation();

    return (
        <div className="min-h-svh bg-background text-foreground lg:grid lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
            <aside className="relative hidden overflow-hidden border-r border-green-900/10 bg-green-50 p-10 lg:flex lg:flex-col xl:p-12 dark:border-white/5 dark:bg-green-950/30">
                <BrandLink name={name} />

                <div
                    aria-hidden
                    className="pointer-events-none absolute inset-0"
                >
                    <AppLogoIcon className="absolute top-[18%] -right-20 size-[22rem] rotate-[8deg] fill-green-700/[0.07] dark:fill-green-400/[0.06]" />
                    <AppLogoIcon className="absolute top-[12%] right-[42%] size-24 -rotate-[10deg] fill-green-700/[0.12] dark:fill-green-400/[0.1]" />
                    <AppLogoIcon className="absolute top-[46%] right-[34%] size-36 rotate-[4deg] fill-green-700/[0.1] dark:fill-green-400/[0.08]" />
                </div>

                <div className="relative mt-auto max-w-md">
                    <p className="text-3xl leading-tight font-semibold tracking-tight text-balance xl:text-4xl">
                        {t('welcome.hero.title')}
                    </p>
                    <p className="mt-4 text-lg text-pretty text-muted-foreground">
                        {t('welcome.hero.who')}
                    </p>
                </div>
            </aside>

            <div className="flex min-h-svh flex-col">
                <header className="flex items-center justify-between gap-3 px-4 py-4 sm:px-6 lg:justify-end lg:px-10 lg:py-6">
                    <BrandLink name={name} className="lg:hidden" />
                    <LanguageSwitcher compact className="sm:hidden" />
                    <LanguageSwitcher className="max-sm:hidden" />
                </header>

                <main className="flex flex-1 flex-col items-center px-4 pt-6 pb-10 sm:justify-center sm:px-6 sm:pt-0">
                    <div className="w-full max-w-sm">
                        <div className="mb-8 text-center">
                            <h1 className="text-2xl font-semibold tracking-tight text-balance">
                                {title}
                            </h1>
                            {description && (
                                <p className="mt-2 text-sm text-balance text-muted-foreground">
                                    {description}
                                </p>
                            )}
                        </div>
                        {children}
                    </div>
                </main>

                <footer className="flex flex-wrap items-center justify-center gap-x-6 gap-y-2 px-4 py-6 text-sm text-muted-foreground">
                    <Link
                        href={privacy()}
                        className="underline-offset-4 hover:text-foreground hover:underline"
                    >
                        {t('nav.privacy')}
                    </Link>
                    <Link
                        href={credits()}
                        className="underline-offset-4 hover:text-foreground hover:underline"
                    >
                        {t('nav.credits')}
                    </Link>
                </footer>
            </div>

            <InstallBanner />
        </div>
    );
}

/** The logo's tile and the app's name, home to the welcome page. */
function BrandLink({ name, className }: { name: string; className?: string }) {
    return (
        <Link
            href={home()}
            className={cn(
                'relative flex w-fit items-center gap-2.5 rounded-lg text-lg font-semibold tracking-tight',
                className,
            )}
        >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-green-700 text-white">
                <AppLogoIcon className="size-6 fill-current" />
            </span>
            {name}
        </Link>
    );
}
