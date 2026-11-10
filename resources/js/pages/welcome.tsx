import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageSwitcher } from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { credits, login, privacy, register } from '@/routes';
import { index as classes } from '@/routes/classes';

export default function Welcome() {
    const { auth, name } = usePage().props;
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('welcome.title')} />

            <div className="flex min-h-svh flex-col items-center bg-background p-6 text-foreground lg:p-8">
                <header className="flex w-full max-w-4xl items-center justify-between gap-4">
                    <LanguageSwitcher />

                    <nav className="flex items-center gap-2">
                        {auth.user ? (
                            <Button asChild variant="outline">
                                <Link href={classes()}>{t('nav.classes')}</Link>
                            </Button>
                        ) : (
                            <>
                                <Button asChild variant="ghost">
                                    <Link href={login()}>
                                        {t('nav.log_in')}
                                    </Link>
                                </Button>
                                <Button asChild variant="outline">
                                    <Link href={register()}>
                                        {t('nav.register')}
                                    </Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </header>

                <main className="flex w-full max-w-2xl flex-1 flex-col items-center justify-center gap-6 py-16 text-center">
                    <div className="flex size-20 items-center justify-center rounded-2xl bg-green-700 text-white">
                        <AppLogoIcon className="size-14 fill-current" />
                    </div>

                    <h1 className="text-4xl font-semibold tracking-tight">
                        {name}
                    </h1>

                    <p className="text-lg text-balance text-muted-foreground">
                        {t('welcome.tagline')}
                    </p>

                    <p className="text-sm text-muted-foreground">
                        {t('welcome.audience')}
                    </p>
                </main>

                <footer className="flex flex-wrap items-center justify-center gap-4">
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
        </>
    );
}
