import { Head, Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageSwitcher } from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { home, login, start } from '@/routes';

/** One entry of `content/credits.json` (`CreditData::toArray()`). */
type Credit = {
    title: string;
    author: string;
    source: string;
    license: { id: string; title: string; url: string };
    /** Paths from the repository root; `*` and `/**` are globs. */
    files: string[];
    /** What Elifba changed in the files, in English, if anything. */
    changes: string | null;
};

type Props = {
    credits: Credit[];
};

const linkClass = 'underline underline-offset-4 break-all';

/**
 * Who made Elifba's pictures, sounds and fonts, and under which licenses
 * (docs/architecture.md §8): public, in every interface language, from the
 * same `content/credits.json` as `CREDITS.md`.
 */
export default function Credits({ credits }: Props) {
    const { auth, name } = usePage().props;
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('credits.title')} />

            <div className="flex min-h-svh flex-col items-center bg-background p-4 text-foreground md:p-8">
                <header className="flex w-full max-w-3xl flex-wrap items-center justify-between gap-4">
                    <Link
                        href={home()}
                        className="flex items-center gap-2 font-semibold"
                    >
                        <AppLogoIcon className="size-7 fill-current" />
                        {name}
                    </Link>
                    <div className="flex flex-wrap items-center gap-2">
                        <LanguageSwitcher />
                        <Button asChild variant="outline" size="sm">
                            {/* A signed-in account goes home by role (architecture §1 "Accounts and roles"). */}
                            <Link href={auth.user ? start() : login()}>
                                {auth.user
                                    ? t(
                                          auth.area === 'parent'
                                              ? 'nav.children'
                                              : 'nav.classes',
                                      )
                                    : t('nav.log_in')}
                            </Link>
                        </Button>
                    </div>
                </header>

                <main className="w-full max-w-3xl space-y-10 py-10">
                    <div className="space-y-3">
                        <h1 className="text-3xl font-semibold tracking-tight">
                            {t('credits.title')}
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            {t('credits.intro')}
                        </p>
                    </div>

                    <section className="space-y-2">
                        <h2 className="text-xl font-semibold">
                            {t('credits.licenses.title')}
                        </h2>
                        <p className="leading-relaxed">
                            {t('credits.licenses.body')}
                        </p>
                    </section>

                    <section className="space-y-4">
                        <h2 className="text-xl font-semibold">
                            {t('credits.files.title')}
                        </h2>
                        <ul className="grid grid-cols-1 gap-4">
                            {credits.map((credit) => (
                                <li
                                    key={credit.files.join()}
                                    className="min-w-0 space-y-3 rounded-xl border bg-card p-4"
                                >
                                    <h3 className="font-semibold">
                                        {credit.title}
                                    </h3>
                                    <dl className="grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-[auto_1fr]">
                                        <dt className="text-muted-foreground">
                                            {t('credits.fields.author')}
                                        </dt>
                                        <dd>{credit.author}</dd>
                                        <dt className="text-muted-foreground">
                                            {t('credits.fields.license')}
                                        </dt>
                                        <dd>
                                            <a
                                                href={credit.license.url}
                                                className={linkClass}
                                            >
                                                {credit.license.title}
                                            </a>
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            {t('credits.fields.source')}
                                        </dt>
                                        <dd className="min-w-0">
                                            <a
                                                href={credit.source}
                                                className={linkClass}
                                            >
                                                {credit.source}
                                            </a>
                                        </dd>
                                        <dt className="text-muted-foreground">
                                            {t('credits.fields.files')}
                                        </dt>
                                        <dd className="min-w-0">
                                            <Files files={credit.files} />
                                        </dd>
                                        {credit.changes !== null && (
                                            <>
                                                <dt className="text-muted-foreground">
                                                    {t(
                                                        'credits.fields.changes',
                                                    )}
                                                </dt>
                                                <dd lang="en">
                                                    {credit.changes}
                                                </dd>
                                            </>
                                        )}
                                    </dl>
                                </li>
                            ))}
                        </ul>
                    </section>
                </main>
            </div>
        </>
    );
}

function Files({ files }: { files: string[] }) {
    return (
        <>
            {files.map((file, index) => (
                <span key={file}>
                    {index > 0 && ', '}
                    <code className="rounded bg-muted px-1 py-0.5 text-xs break-all">
                        {file}
                    </code>
                </span>
            ))}
        </>
    );
}
