import { Form, Link, router } from '@inertiajs/react';
import type { CSSProperties } from 'react';
import { useId, useMemo, useState } from 'react';
import { GameCard } from '@/components/games/game-card';
import { MODE_ICONS, UNIT_ICONS } from '@/components/games/game-icons';
import { GameScene } from '@/components/games/game-scene';
import {
    GameSheet,
    HiddenFields,
    useSummary,
} from '@/components/games/game-sheet';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useReducedMotion } from '@/hooks/use-reduced-motion';
import { useTranslation } from '@/hooks/use-translation';
import {
    anyStartable,
    catalogCards,
    chosenLevel,
    gameExtras,
    openGame,
    postedFields,
    readsUnit,
    readyCard,
    shelves,
    startSummary,
} from '@/lib/setup-choices';
import type { CatalogCard } from '@/lib/setup-choices';
import { cn } from '@/lib/utils';
import type {
    Letter,
    PlayersSetup,
    SetupMode,
    SetupUnit,
    SharedSetup,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

export type GameCatalogProps = SharedSetup &
    PlayersSetup & {
        /** Where a game starts: the setup page's `StartGame`, or a guest board from the phone. */
        form: RouteFormDefinition<'post'>;
        /** Where «Asan / Normal» is kept: `PUT …/games/mode` of the class or the child. */
        modeUrl: string;
        /** Fields sent as they are (the class or the child, on a guest board). */
        hidden?: Record<string, string>;
        submitLabel?: string;
        /** A home game (ADR 011): the picture and number shelves come first. */
        home?: boolean;
        /** The class's name or the child's first name, for «… yadda qalır». */
        name: string;
        /** The learned letters on the class's or the child's page; null on the guest board. */
        lettersHref?: string | null;
        /** Classes that let a sideways row reach the page's edges (its padding, negated). */
        bleed?: string;
    };

const MODES: SetupMode[] = ['easy', 'normal'];

/**
 * «Yeni oyun» (docs/frontend.md rule 13, architecture §2 "Game setup"): a
 * catalog of ready games, not a form of questions. «Asan / Normal» on top
 * (kept for the class or the child at once), the skill chips, «Hazır oyun»
 * — the first game that can start, two taps from «Oyna» — and one shelf
 * per unit of cards that scroll sideways. A card opens its game page
 * (`GameSheet`). The choosing logic is `lib/setup-choices.ts`; what can
 * start is the server's `options`. The setup page and the phone's guest
 * board use it alike, for a class or a parent's child (`home`).
 */
export function GameCatalog({
    packs,
    levels,
    games,
    options,
    cards: serverCards,
    letters,
    allLetters,
    letterCases,
    wordMaxLength,
    remembered,
    form,
    modeUrl,
    hidden = {},
    submitLabel,
    home = false,
    name,
    lettersHref = null,
    bleed,
}: GameCatalogProps) {
    const { t } = useTranslation();
    const summarise = useSummary();
    const reduced = useReducedMotion();
    const prefix = useId();
    const cards = useMemo(
        () =>
            catalogCards(
                { packs, levels, games },
                { cards: serverCards, options },
            ),
        [packs, levels, games, serverCards, options],
    );
    const [mode, setMode] = useState<SetupMode>(remembered.mode);
    const [openId, setOpenId] = useState<string | null>(null);
    const shown = useMemo(
        () => shelves(cards, mode, home),
        [cards, mode, home],
    );
    const ready = readyCard(shown);
    const label = submitLabel ?? t('games.setup.start');
    const firstReading = shown.find((shelf) => readsUnit(shelf.unit))?.unit;
    const headingId = (unit: SetupUnit) => `${prefix}-shelf-${unit}`;

    const changeLetters =
        lettersHref === null ? null : (
            <Link
                href={lettersHref}
                className="font-medium text-foreground underline underline-offset-4"
            >
                {t('games.setup.locked.change')}
            </Link>
        );

    const switchMode = (next: SetupMode) => {
        if (next === mode) {
            return;
        }

        setMode(next);
        router.put(
            modeUrl,
            { mode: next },
            { preserveScroll: true, preserveState: true },
        );
    };

    const jump = (unit: SetupUnit) => {
        const heading = document.getElementById(headingId(unit));

        heading?.scrollIntoView({
            behavior: reduced ? 'auto' : 'smooth',
            block: 'start',
        });
        heading?.focus({ preventScroll: true });
    };

    return (
        <div className="@container flex min-w-0 flex-col gap-6">
            <div className="flex flex-col gap-1.5">
                <div
                    role="group"
                    aria-label={t('games.setup.mode')}
                    className="grid max-w-[560px] grid-cols-2 gap-1 rounded-2xl bg-muted p-1"
                >
                    {MODES.map((item) => {
                        const Icon = MODE_ICONS[item];
                        const chosen = item === mode;

                        return (
                            <button
                                key={item}
                                type="button"
                                aria-pressed={chosen}
                                onClick={() => switchMode(item)}
                                className={cn(
                                    'flex min-h-[58px] min-w-0 items-center gap-2.5 rounded-xl px-2.5 py-2 text-left leading-tight outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                    chosen
                                        ? 'bg-background text-foreground shadow-sm'
                                        : 'text-muted-foreground hover:bg-background/50',
                                )}
                            >
                                <span
                                    className={cn(
                                        'grid size-[38px] shrink-0 place-items-center rounded-[11px]',
                                        chosen
                                            ? 'bg-primary text-primary-foreground'
                                            : 'bg-foreground/5',
                                    )}
                                >
                                    <Icon aria-hidden className="size-5" />
                                </span>
                                <span className="flex min-w-0 flex-col">
                                    <span className="text-[15px] font-semibold text-foreground">
                                        {t(`games.setup.modes.${item}`)}
                                    </span>
                                    <span className="truncate text-xs">
                                        {t(`games.setup.mode_hints.${item}`)}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                </div>
                <p className="text-[12.5px] text-muted-foreground">
                    {t('games.setup.remembered', { name })}
                </p>
            </div>

            {!anyStartable(cards) ? (
                <p className="rounded-xl border border-dashed p-4 text-sm text-muted-foreground">
                    {t('games.setup.none')} {changeLetters}
                </p>
            ) : (
                <>
                    <nav
                        aria-label={t('games.setup.skills')}
                        className={cn(
                            '-my-1 flex [scrollbar-width:none] gap-2 overflow-x-auto py-1',
                            bleed,
                        )}
                    >
                        {shown.map((shelf) => {
                            const Icon = UNIT_ICONS[shelf.unit];

                            return (
                                <button
                                    key={shelf.unit}
                                    type="button"
                                    onClick={() => jump(shelf.unit)}
                                    className="inline-flex h-10 shrink-0 items-center gap-1.5 rounded-full border bg-background pr-3.5 pl-3 text-sm font-medium outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                >
                                    <Icon
                                        aria-hidden
                                        className="size-4 opacity-80"
                                    />
                                    {t(`games.setup.units.${shelf.unit}`)}
                                </button>
                            );
                        })}
                    </nav>

                    {ready !== undefined && (
                        <ReadyGame
                            key={ready.id}
                            card={ready}
                            remembered={remembered}
                            letters={letters}
                            form={form}
                            hidden={hidden}
                            label={label}
                            summarise={summarise}
                            onOpen={() => setOpenId(ready.id)}
                        />
                    )}

                    {shown.map((shelf) => {
                        const Icon = UNIT_ICONS[shelf.unit];

                        return (
                            <section
                                key={shelf.unit}
                                aria-labelledby={headingId(shelf.unit)}
                                className="flex min-w-0 flex-col gap-1.5"
                            >
                                <div className="flex items-center gap-3">
                                    <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-muted">
                                        <Icon aria-hidden className="size-5" />
                                    </span>
                                    <div className="min-w-0">
                                        <h2
                                            id={headingId(shelf.unit)}
                                            tabIndex={-1}
                                            className="scroll-mt-24 text-lg leading-tight font-bold tracking-tight outline-none"
                                        >
                                            {t(
                                                `games.setup.units.${shelf.unit}`,
                                            )}
                                        </h2>
                                        <p className="text-[12.5px] text-muted-foreground">
                                            {t('games.setup.shelf', {
                                                hint: t(
                                                    `games.setup.unit_hints.${shelf.unit}`,
                                                ),
                                                count: shelf.cards.length,
                                            })}
                                        </p>
                                    </div>
                                </div>

                                {shelf.unit === firstReading && (
                                    <LearnedLetters
                                        letters={letters}
                                        allLetters={allLetters}
                                        lettersHref={lettersHref}
                                    />
                                )}

                                <div
                                    className={cn(
                                        'flex snap-x snap-mandatory [scrollbar-width:thin] gap-3.5 overflow-x-auto pt-2 pb-2.5',
                                        bleed,
                                    )}
                                >
                                    {shelf.cards.map((card) => (
                                        <GameCard
                                            key={card.id}
                                            card={card}
                                            onOpen={() => setOpenId(card.id)}
                                        />
                                    ))}
                                </div>
                            </section>
                        );
                    })}
                </>
            )}

            <GameSheet
                card={cards.find((card) => card.id === openId) ?? null}
                onClose={() => setOpenId(null)}
                remembered={remembered}
                letters={letters}
                letterCases={letterCases}
                wordMaxLength={wordMaxLength}
                form={form}
                hidden={hidden}
                submitLabel={label}
                teacher={!home}
                name={name}
                lettersHref={lettersHref}
            />
        </div>
    );
}

/**
 * «Hazır oyun»: the first card that can start, with its remembered choices,
 * started in one tap; «Seçimlər» (or a tap on its scene) opens its page.
 */
function ReadyGame({
    card,
    remembered,
    letters,
    form,
    hidden,
    label,
    summarise,
    onOpen,
}: {
    card: CatalogCard;
    remembered: PlayersSetup['remembered'];
    letters: Letter[];
    form: RouteFormDefinition<'post'>;
    hidden: Record<string, string>;
    label: string;
    summarise: ReturnType<typeof useSummary>;
    onOpen: () => void;
}) {
    const { t } = useTranslation();
    const titleId = useId();
    const choice = openGame(card, remembered, letters);

    if (choice === null) {
        return null;
    }

    const level = chosenLevel(card, choice);
    const summary = [
        level?.level.title ?? '',
        summarise(startSummary(gameExtras(card, choice))),
    ]
        .filter((part) => part !== '')
        .join(' · ');
    const [p0 = '#3B82C4'] = card.pack.palette;
    const style: CSSProperties = {
        background: `color-mix(in srgb, ${p0} 12%, var(--background))`,
        boxShadow: `inset 0 0 0 1px color-mix(in srgb, ${p0} 28%, transparent)`,
    };

    return (
        <section
            aria-labelledby={titleId}
            style={style}
            className="grid grid-cols-1 overflow-hidden rounded-3xl @min-[700px]:grid-cols-[340px_minmax(0,1fr)]"
        >
            <button
                type="button"
                onClick={onOpen}
                aria-label={t('games.setup.options')}
                className="block outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:ring-inset"
            >
                <GameScene
                    wide
                    pack={card.pack}
                    unit={card.card.unit}
                    mechanic={card.card.mechanic}
                    sample={card.card.sample}
                />
            </button>
            <div className="flex min-w-0 flex-col gap-1.5 px-4 pt-3.5 pb-4 @min-[700px]:justify-center @min-[700px]:px-7 @min-[700px]:py-6">
                <span className="self-start rounded-full bg-background px-2.5 py-0.5 text-[11px] font-bold tracking-[0.08em] uppercase">
                    {t('games.setup.ready')}
                </span>
                <h2
                    id={titleId}
                    className="text-[22px] leading-tight font-bold tracking-tight text-balance @min-[700px]:text-[26px]"
                >
                    {card.card.title}
                </h2>
                {summary !== '' && (
                    <p className="text-[13px] text-muted-foreground">
                        {summary}
                    </p>
                )}
                <Form
                    {...form}
                    options={{ preserveScroll: true, preserveState: true }}
                    className="mt-1.5 flex flex-col gap-1"
                >
                    {({ processing, errors }) => (
                        <>
                            <HiddenFields
                                fields={{
                                    ...hidden,
                                    ...postedFields(card, choice),
                                }}
                            />
                            <Button
                                type="submit"
                                size="lg"
                                disabled={processing}
                                className="h-[52px] w-full rounded-xl text-base @min-[700px]:max-w-[260px]"
                            >
                                {processing && <Spinner />}
                                {label}
                            </Button>
                            <InputError message={Object.values(errors)[0]} />
                        </>
                    )}
                </Form>
                <button
                    type="button"
                    onClick={onOpen}
                    className="min-h-11 self-center text-[13px] font-medium underline underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 @min-[700px]:self-start"
                >
                    {t('games.setup.options')}
                </button>
            </div>
        </section>
    );
}

/** Which letters the reading games use, with «Dəyiş» to the learned letters (none on the guest board). */
function LearnedLetters({
    letters,
    allLetters,
    lettersHref,
}: {
    letters: Letter[];
    allLetters: boolean;
    lettersHref: string | null;
}) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-0.5 text-sm text-muted-foreground">
            <p>
                {allLetters
                    ? t('games.setup.learned.all')
                    : t('games.setup.learned.some', { count: letters.length })}
            </p>
            {(!allLetters || lettersHref !== null) && (
                <p className="flex flex-wrap items-baseline gap-x-3">
                    {!allLetters && (
                        <span
                            lang="az"
                            className="font-board text-base tracking-[0.2em] text-foreground"
                        >
                            {letters.map((letter) => letter.upper).join(' ')}
                        </span>
                    )}
                    {lettersHref !== null && (
                        <Link
                            href={lettersHref}
                            className="font-medium text-foreground underline underline-offset-4"
                        >
                            {t('games.setup.learned.change')}
                        </Link>
                    )}
                </p>
            )}
        </div>
    );
}
