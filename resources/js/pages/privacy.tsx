import { Head, Link, usePage } from '@inertiajs/react';
import { Check, Copy } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LanguageSwitcher } from '@/components/language-switcher';
import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTranslation } from '@/hooks/use-translation';
import { home, login, start } from '@/routes';

type Props = {
    retentionDays: number;
    /** At most this many children per parent's account (ADR 013). */
    childrenMax: number;
    /** Whether this instance offers Google sign-in: then the notice says what it shares (ADR 008). */
    googleSignIn: boolean;
    /** Who runs this instance, from `ELIFBA_OPERATOR_*`; null when not set. */
    operator: { name: string | null; email: string | null };
};

/** The sections of the notice, in order; each has a title and a body key. */
const SECTIONS = ['stored', 'not_stored', 'why', 'how_long', 'delete', 'where'];

/** The parts of the section for parents at home, in order (ADR 013). */
const HOME = ['stored', 'why', 'who', 'not_shared', 'delete', 'how_long'];

/**
 * The privacy notice (docs/architecture.md §7), public and in every
 * interface language: what Elifba keeps for a class, a section for parents
 * who play with their own children at home (§7 "Parents and their
 * children"), and the note a teacher copies for a class's parents.
 */
export default function Privacy({
    retentionDays,
    childrenMax,
    googleSignIn,
    operator,
}: Props) {
    const { auth, name } = usePage().props;
    const { t } = useTranslation();
    const [, copy] = useClipboard();
    const note = useRef<HTMLQuoteElement>(null);
    const [copied, setCopied] = useState<'yes' | 'selected' | null>(null);
    const days = { days: retentionDays };
    const parentNote = t('privacy.parents.note', days);

    return (
        <>
            <Head title={t('privacy.title')} />

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
                            {t('privacy.title')}
                        </h1>
                        <p className="text-lg text-muted-foreground">
                            {t('privacy.intro')}
                        </p>
                    </div>

                    {SECTIONS.map((section) => (
                        <section key={section} className="space-y-2">
                            <h2 className="text-xl font-semibold">
                                {t(`privacy.${section}.title`)}
                            </h2>
                            <p className="leading-relaxed whitespace-pre-line">
                                {t(`privacy.${section}.body`, days)}
                            </p>
                        </section>
                    ))}

                    <section
                        className="space-y-3"
                        aria-labelledby="home"
                        data-test="home"
                    >
                        <h2 id="home" className="text-xl font-semibold">
                            {t('privacy.home.title')}
                        </h2>
                        {HOME.map((part) => (
                            <p
                                key={part}
                                className="leading-relaxed whitespace-pre-line"
                            >
                                {t(`privacy.home.${part}`, {
                                    ...days,
                                    max: childrenMax,
                                })}
                            </p>
                        ))}
                    </section>

                    {googleSignIn && (
                        <section className="space-y-2" data-test="google">
                            <h2 className="text-xl font-semibold">
                                {t('privacy.google.title')}
                            </h2>
                            <p className="leading-relaxed whitespace-pre-line">
                                {t('privacy.google.body')}
                            </p>
                        </section>
                    )}

                    <section className="space-y-2">
                        <h2 className="text-xl font-semibold">
                            {t('privacy.operator.title')}
                        </h2>
                        <p className="leading-relaxed" data-test="operator">
                            <Operator {...operator} />
                        </p>
                    </section>

                    <section
                        className="space-y-4 rounded-xl border bg-card p-4 md:p-6"
                        aria-labelledby="parent-note"
                    >
                        <div className="space-y-1">
                            <h2
                                id="parent-note"
                                className="text-xl font-semibold"
                            >
                                {t('privacy.parents.title')}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {t('privacy.parents.description')}
                            </p>
                        </div>
                        <blockquote
                            ref={note}
                            className="rounded-lg bg-muted p-4 leading-relaxed whitespace-pre-line"
                        >
                            {parentNote}
                        </blockquote>
                        <div className="flex flex-wrap items-center gap-3">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    void copyNote(
                                        parentNote,
                                        note.current,
                                        copy,
                                    ).then((result) => {
                                        setCopied(result);
                                        window.setTimeout(
                                            () => setCopied(null),
                                            result === 'yes' ? 2000 : 6000,
                                        );
                                    });
                                }}
                            >
                                {copied === 'yes' ? <Check /> : <Copy />}
                                {copied === 'yes'
                                    ? t('privacy.parents.copied')
                                    : t('privacy.parents.copy')}
                            </Button>
                            <span
                                aria-live="polite"
                                className="text-sm text-muted-foreground"
                            >
                                {copied === 'selected'
                                    ? t('privacy.parents.selected')
                                    : ''}
                                <span className="sr-only">
                                    {copied === 'yes'
                                        ? t('privacy.parents.copied')
                                        : ''}
                                </span>
                            </span>
                        </div>
                    </section>
                </main>
            </div>
        </>
    );
}

/**
 * Copies the parent note. The Clipboard API exists only on HTTPS (and
 * localhost); a self-hosted instance on plain HTTP falls back to selecting
 * the note and the legacy copy command, and if even that fails the note stays
 * selected for the teacher to copy by hand.
 */
async function copyNote(
    text: string,
    element: HTMLElement | null,
    copy: (text: string) => Promise<boolean>,
): Promise<'yes' | 'selected'> {
    if (window.isSecureContext && (await copy(text))) {
        return 'yes';
    }

    const selection = window.getSelection();

    if (!element || !selection) {
        return 'selected';
    }

    selection.selectAllChildren(element);

    try {
        // Deprecated, but the only copy available outside a secure context.
        return document.execCommand('copy') ? 'yes' : 'selected';
    } catch {
        return 'selected';
    }
}

function Operator({ name, email }: Props['operator']) {
    const { t } = useTranslation();

    if (name === null && email === null) {
        return t('privacy.operator.unknown');
    }

    return (
        <>
            {t('privacy.operator.known')}{' '}
            {name !== null && <strong>{name}</strong>}
            {name !== null && email !== null && <span aria-hidden> · </span>}
            {email !== null && (
                <a
                    href={`mailto:${email}`}
                    className="underline underline-offset-4"
                >
                    {email}
                </a>
            )}
        </>
    );
}
