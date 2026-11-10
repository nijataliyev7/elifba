import { Head, Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import boardImage from '../../images/welcome/board.webp';
import classImage from '../../images/welcome/class.webp';
import podiumImage from '../../images/welcome/podium.webp';
import remoteImage from '../../images/welcome/remote.webp';
import setupImage from '../../images/welcome/setup.webp';
import AppLogoIcon from '@/components/app-logo-icon';
import { GitHubLink } from '@/components/github-link';
import { LanguageSwitcher } from '@/components/language-switcher';
import { Button, buttonVariants } from '@/components/ui/button';
import { SampleWall } from '@/components/welcome/sample-wall';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import { credits, home, login, privacy, register } from '@/routes';
import { index as classes } from '@/routes/classes';
import type { WelcomeProps } from '@/types/welcome';

/** The logo's green, for the main action and the step numbers (frontend.md rule 10). */
const MAIN_ACTION =
    'bg-green-700 text-white shadow-xs hover:bg-green-800 focus-visible:ring-green-700/40';

/**
 * The landing page (`/`, frontend.md rule 10): what Elifba is, how a lesson
 * goes, what a teacher gets, what is kept about children and what it costs.
 * Every fact is a prop from `WelcomeController`.
 */
export default function Welcome({
    sourceUrl,
    mechanics,
    packs,
    letters,
    avatars,
    sample,
}: WelcomeProps) {
    const { auth, name } = usePage().props;
    const { t } = useTranslation();
    const teacher = auth.user !== null;

    const mainAction = teacher ? (
        <Button asChild size="lg" className={MAIN_ACTION}>
            <Link href={classes()}>{t('welcome.hero.classes')}</Link>
        </Button>
    ) : (
        <Button asChild size="lg" className={MAIN_ACTION}>
            <Link href={register()}>{t('welcome.hero.register')}</Link>
        </Button>
    );

    const sourceAction = (
        <GitHubLink
            href={sourceUrl}
            className={buttonVariants({ variant: 'outline', size: 'lg' })}
        >
            {t('welcome.source')}
        </GitHubLink>
    );

    return (
        <>
            <Head title={t('welcome.title')} />

            <div className="min-h-svh overflow-x-clip bg-background text-foreground">
                <header className="mx-auto flex w-full max-w-7xl items-center gap-2 px-4 py-4 sm:px-6 lg:px-8">
                    <Link
                        href={home()}
                        className="mr-auto flex min-w-0 items-center gap-2.5 rounded-lg text-lg font-semibold"
                    >
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-green-700 text-white">
                            <AppLogoIcon className="size-6 fill-current" />
                        </span>
                        {name}
                    </Link>

                    <LanguageSwitcher compact className="sm:hidden" />
                    <LanguageSwitcher className="max-sm:hidden" />

                    <GitHubLink
                        href={sourceUrl}
                        aria-label={t('welcome.source')}
                        className={cn(
                            buttonVariants({ variant: 'ghost' }),
                            'max-md:hidden',
                        )}
                    >
                        {t('welcome.github')}
                    </GitHubLink>

                    {teacher ? (
                        <Button asChild variant="outline">
                            <Link href={classes()}>{t('nav.classes')}</Link>
                        </Button>
                    ) : (
                        <>
                            <Button asChild variant="ghost">
                                <Link href={login()}>{t('nav.log_in')}</Link>
                            </Button>
                            <Button
                                asChild
                                variant="outline"
                                className="max-md:hidden"
                            >
                                <Link href={register()}>
                                    {t('nav.register')}
                                </Link>
                            </Button>
                        </>
                    )}
                </header>

                <main>
                    <section className="mx-auto grid w-full max-w-7xl grid-cols-1 gap-x-12 gap-y-8 px-4 pt-6 pb-20 sm:px-6 lg:grid-cols-12 lg:px-8 lg:pt-16 lg:pb-32">
                        <div className="self-end lg:col-span-5">
                            <h1 className="max-w-xl text-4xl leading-[1.08] font-semibold tracking-tight text-balance sm:text-5xl xl:text-6xl">
                                {t('welcome.hero.title')}
                            </h1>
                            <p className="mt-5 max-w-lg text-lg text-pretty text-muted-foreground lg:mt-6 lg:text-xl">
                                {t('welcome.hero.how')}
                            </p>
                        </div>

                        {sample !== null && (
                            <div className="min-w-0 lg:col-span-7 lg:col-start-6 lg:row-span-2 lg:row-start-1 lg:self-center">
                                <SampleWall sample={sample} />
                            </div>
                        )}

                        <div className="self-start lg:col-span-5">
                            <p className="max-w-lg text-lg text-pretty lg:text-xl">
                                {t('welcome.hero.who')}
                            </p>
                            <div className="mt-6 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                {mainAction}
                                {sourceAction}
                            </div>
                        </div>
                    </section>

                    <section
                        aria-labelledby="welcome-lesson"
                        className="mx-auto w-full max-w-7xl px-4 pb-24 sm:px-6 lg:px-8 lg:pb-32"
                    >
                        <h2
                            id="welcome-lesson"
                            className="text-3xl font-semibold tracking-tight lg:text-4xl"
                        >
                            {t('welcome.lesson.title')}
                        </h2>

                        <ol
                            role="list"
                            className="mt-10 grid grid-cols-1 gap-x-12 gap-y-16 lg:mt-14 lg:grid-cols-2 lg:gap-y-20"
                        >
                            <Step
                                number={1}
                                title={t('welcome.lesson.class.title')}
                                text={t('welcome.lesson.class.text')}
                            >
                                <Picture
                                    src={classImage}
                                    width={1200}
                                    height={750}
                                    alt={t('welcome.lesson.class.alt')}
                                    position="object-top"
                                />
                            </Step>
                            <Step
                                number={2}
                                title={t('welcome.lesson.setup.title')}
                                text={t('welcome.lesson.setup.text')}
                            >
                                <Picture
                                    src={setupImage}
                                    width={1200}
                                    height={750}
                                    alt={t('welcome.lesson.setup.alt')}
                                    position="object-top"
                                />
                            </Step>
                            <Step
                                number={3}
                                title={t('welcome.lesson.play.title')}
                                text={`${t('welcome.lesson.play.text')} ${t('welcome.lesson.play.turn')}`}
                            >
                                <div className="relative mr-3 mb-10 sm:mr-5">
                                    <Picture
                                        src={boardImage}
                                        width={1200}
                                        height={675}
                                        alt={t('welcome.lesson.play.alt')}
                                        position="object-center"
                                    />
                                    <img
                                        src={remoteImage}
                                        width={540}
                                        height={559}
                                        loading="lazy"
                                        decoding="async"
                                        alt={t(
                                            'welcome.lesson.play.remote_alt',
                                        )}
                                        className="absolute -right-3 -bottom-10 w-[26%] rounded-xl bg-white shadow-xl ring-1 shadow-black/15 ring-black/10 sm:-right-5 dark:ring-white/15"
                                    />
                                </div>
                            </Step>
                            <Step
                                number={4}
                                title={t('welcome.lesson.share.title')}
                                text={t('welcome.lesson.share.text')}
                            >
                                <Picture
                                    src={podiumImage}
                                    width={1200}
                                    height={1000}
                                    alt={t('welcome.lesson.share.alt')}
                                    position="object-bottom"
                                />
                            </Step>
                        </ol>
                    </section>

                    <section
                        aria-labelledby="welcome-get"
                        className="mx-auto grid w-full max-w-7xl grid-cols-1 gap-x-12 gap-y-10 px-4 pb-24 sm:px-6 lg:grid-cols-12 lg:px-8 lg:pb-32"
                    >
                        <h2
                            id="welcome-get"
                            className="text-3xl font-semibold tracking-tight lg:col-span-4 lg:text-4xl"
                        >
                            {t('welcome.get.title')}
                        </h2>

                        <dl className="grid min-w-0 grid-cols-1 gap-x-10 gap-y-14 sm:grid-cols-3 lg:col-span-8">
                            <div className="sm:col-span-3">
                                <dt className="text-xl font-semibold">
                                    {t('welcome.get.mechanics', {
                                        count: mechanics.length,
                                    })}
                                </dt>
                                <dd className="mt-5">
                                    <ul className="grid grid-cols-1 gap-x-10 gap-y-5 sm:grid-cols-2">
                                        {mechanics.map((mechanic) => (
                                            <li key={mechanic}>
                                                <p className="font-semibold">
                                                    {t(
                                                        `games.mechanics.${mechanic}`,
                                                    )}
                                                </p>
                                                <p className="mt-1 text-muted-foreground">
                                                    {t(
                                                        `welcome.get.mechanic.${mechanic}`,
                                                    )}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                </dd>
                            </div>

                            <div className="sm:col-span-3">
                                <dt className="text-xl font-semibold">
                                    {t('welcome.get.packs', {
                                        count: packs.length,
                                    })}
                                </dt>
                                <dd className="mt-2">
                                    <p className="text-muted-foreground">
                                        {t('welcome.get.packs_text')}
                                    </p>
                                    <ul className="mt-5 flex flex-wrap gap-2">
                                        {packs.map((pack) => (
                                            <li
                                                key={pack.key}
                                                className="flex items-center gap-2.5 rounded-full border py-1.5 pr-4 pl-2"
                                            >
                                                <span
                                                    aria-hidden
                                                    className="flex -space-x-1.5"
                                                >
                                                    {pack.palette
                                                        .slice(0, 4)
                                                        .map((colour) => (
                                                            <span
                                                                key={colour}
                                                                className="size-5 rounded-full border-2 border-background"
                                                                style={{
                                                                    backgroundColor:
                                                                        colour,
                                                                }}
                                                            />
                                                        ))}
                                                </span>
                                                {pack.title}
                                            </li>
                                        ))}
                                    </ul>
                                </dd>
                            </div>

                            <div className="sm:col-span-3">
                                <dt className="text-xl font-semibold">
                                    {t('welcome.get.letters')}
                                </dt>
                                <dd className="mt-2">
                                    <p className="text-muted-foreground">
                                        {t('welcome.get.letters_text')}
                                    </p>
                                    <ul
                                        lang="az"
                                        className="mt-5 grid grid-cols-8 gap-1.5 font-board sm:grid-cols-[repeat(16,minmax(0,1fr))]"
                                    >
                                        {letters.map((letter) => (
                                            <li
                                                key={letter.lower}
                                                className="flex aspect-square items-center justify-center rounded-md bg-muted text-lg leading-none sm:text-xl"
                                            >
                                                {letter.upper}
                                                {letter.lower}
                                            </li>
                                        ))}
                                    </ul>
                                </dd>
                            </div>

                            <Fact
                                title={t('welcome.get.words')}
                                text={t('welcome.get.words_text')}
                            />
                            <Fact
                                title={t('welcome.get.guest')}
                                text={t('welcome.get.guest_text')}
                            />
                            <Fact
                                title={t('welcome.get.touch')}
                                text={t('welcome.get.touch_text')}
                            />
                        </dl>
                    </section>

                    <section
                        aria-labelledby="welcome-privacy"
                        className="mx-auto grid w-full max-w-7xl grid-cols-1 gap-x-12 gap-y-6 px-4 pb-24 sm:px-6 lg:grid-cols-12 lg:px-8 lg:pb-32"
                    >
                        <h2
                            id="welcome-privacy"
                            className="text-3xl font-semibold tracking-tight lg:col-span-4 lg:text-4xl"
                        >
                            {t('welcome.privacy.title')}
                        </h2>
                        <div className="lg:col-span-8">
                            <ul aria-hidden className="flex -space-x-3">
                                {avatars.map((avatar) => (
                                    <li
                                        key={avatar.key}
                                        className="flex size-14 items-center justify-center rounded-full bg-muted ring-4 ring-background sm:size-16"
                                    >
                                        <img
                                            src={avatar.url}
                                            alt=""
                                            width={48}
                                            height={48}
                                            loading="lazy"
                                            className="size-11 sm:size-12"
                                        />
                                    </li>
                                ))}
                            </ul>
                            <p className="mt-6 max-w-2xl text-lg text-pretty">
                                {t('welcome.privacy.text')}
                            </p>
                            <p className="mt-3 max-w-2xl text-lg text-pretty text-muted-foreground">
                                {t('welcome.privacy.wire')}
                            </p>
                            <Link
                                href={privacy()}
                                className="mt-5 inline-block font-medium underline underline-offset-4 hover:decoration-2"
                            >
                                {t('welcome.privacy.link')}
                            </Link>
                        </div>
                    </section>

                    <section
                        aria-labelledby="welcome-free"
                        className="mx-auto grid w-full max-w-7xl grid-cols-1 gap-x-12 gap-y-6 px-4 pb-24 sm:px-6 lg:grid-cols-12 lg:px-8 lg:pb-32"
                    >
                        <h2
                            id="welcome-free"
                            className="text-3xl font-semibold tracking-tight lg:col-span-4 lg:text-4xl"
                        >
                            {t('welcome.free.title')}
                        </h2>
                        <div className="lg:col-span-8">
                            <p className="max-w-2xl text-2xl leading-snug font-medium text-pretty lg:text-3xl">
                                {t('welcome.free.text')}
                            </p>
                            <p className="mt-4 max-w-2xl text-lg text-pretty text-muted-foreground">
                                {t('welcome.free.source_text')}
                            </p>
                            <div className="mt-8 flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                                {mainAction}
                                {sourceAction}
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="border-t">
                    <div className="mx-auto flex w-full max-w-7xl flex-wrap items-center gap-x-6 gap-y-3 px-4 py-8 text-sm text-muted-foreground sm:px-6 lg:px-8">
                        <span className="mr-auto flex items-center gap-2 font-medium text-foreground">
                            <span className="flex size-6 items-center justify-center rounded-md bg-green-700 text-white">
                                <AppLogoIcon className="size-4 fill-current" />
                            </span>
                            {name}
                        </span>
                        <Link
                            href={privacy()}
                            className="underline-offset-4 hover:underline"
                        >
                            {t('nav.privacy')}
                        </Link>
                        <Link
                            href={credits()}
                            className="underline-offset-4 hover:underline"
                        >
                            {t('nav.credits')}
                        </Link>
                        <GitHubLink
                            href={sourceUrl}
                            className="underline-offset-4 hover:underline"
                        >
                            {t('welcome.github')}
                        </GitHubLink>
                    </div>
                </footer>
            </div>
        </>
    );
}

type StepProps = {
    number: number;
    title: string;
    text: string;
    children: ReactNode;
};

/** One step of «Dərs necə keçir», the page's only sequence. */
function Step({ number, title, text, children }: StepProps) {
    return (
        <li className="min-w-0">
            <div className="flex gap-4">
                <span
                    aria-hidden
                    className="w-7 shrink-0 text-4xl leading-none font-semibold text-green-700 tabular-nums dark:text-green-500"
                >
                    {number}
                </span>
                <div className="min-w-0">
                    <h3 className="text-xl font-semibold">{title}</h3>
                    <p className="mt-2 max-w-prose text-pretty text-muted-foreground">
                        {text}
                    </p>
                </div>
            </div>
            <div className="mt-6">{children}</div>
        </li>
    );
}

type PictureProps = {
    src: string;
    width: number;
    height: number;
    alt: string;
    /** Which part stays in the 16:9 frame. */
    position: 'object-top' | 'object-center' | 'object-bottom';
};

/** A screenshot of the app in a 16:9 frame, loaded when it comes near. */
function Picture({ src, width, height, alt, position }: PictureProps) {
    return (
        <img
            src={src}
            width={width}
            height={height}
            alt={alt}
            loading="lazy"
            decoding="async"
            className={cn(
                'aspect-video w-full rounded-xl bg-muted object-cover ring-1 ring-black/10 dark:ring-white/15',
                position,
            )}
        />
    );
}

function Fact({ title, text }: { title: string; text: string }) {
    return (
        <div>
            <dt className="font-semibold">{title}</dt>
            <dd className="mt-1 text-pretty text-muted-foreground">{text}</dd>
        </div>
    );
}
