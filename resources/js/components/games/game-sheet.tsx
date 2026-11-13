import { Form, Link } from '@inertiajs/react';
import { ChevronLeft, Lock, X } from 'lucide-react';
import { useState } from 'react';
import { GameScene } from '@/components/games/game-scene';
import { MODE_ICONS, UNIT_ICONS } from '@/components/games/game-icons';
import { SetupExtras, SetupLabel } from '@/components/games/setup-extras';
import type { CatalogWords } from '@/components/words/my-words-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import {
    chooseLevel,
    chosenLevel,
    extrasSummary,
    gameExtras,
    isLettersShortage,
    lockedLine,
    openGame,
    openWithOwnWords,
    playsOwnWords,
    postedFields,
    startSummary,
} from '@/lib/setup-choices';
import type {
    CardLevel,
    CatalogCard,
    Fragment,
    GameChoice,
} from '@/lib/setup-choices';
import { cn } from '@/lib/utils';
import type { Letter, RememberedSetup } from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';
import { useLine } from '@/components/games/game-card';

export type GameSheetProps = {
    /** The card whose page is open; null when closed. */
    card: CatalogCard | null;
    /** Opened by «Bu sözlərlə oyna»: «Yalnız mənim sözlərim» on (`openWithOwnWords`). */
    ownWords: boolean;
    onClose: () => void;
    remembered: RememberedSetup;
    letters: Letter[];
    letterCases: ('upper' | 'lower')[];
    /** Where the game starts: the setup page's `StartGame`, or a guest board. */
    form: RouteFormDefinition<'post'>;
    /** Fields sent as they are (the class or the child, on a guest board); their errors show at the top. */
    hidden: Record<string, string>;
    submitLabel: string;
    /** The teacher area: «Əlavə» offers «Sözlərim» when own words cannot start; null in the parent area. */
    words: CatalogWords | null;
    /** The class's name or the child's first name, for «… yadda qalır». */
    name: string;
    /** The learned letters on the class's or the child's page; null on the guest board. */
    lettersHref: string | null;
};

/** A form's fields as hidden inputs; a list goes as `name[]`. */
export function HiddenFields({
    fields,
}: {
    fields: Record<string, string | string[]>;
}) {
    return Object.entries(fields).flatMap(([field, value]) =>
        Array.isArray(value) ? (
            value.map((item) => (
                <input
                    key={`${field}.${item}`}
                    type="hidden"
                    name={`${field}[]`}
                    value={item}
                />
            ))
        ) : (
            <input key={field} type="hidden" name={field} value={value} />
        ),
    );
}

/** Joins translated fragments with « · ». */
export function useSummary(): (fragments: Fragment[]) => string {
    const { t } = useTranslation();

    return (fragments) =>
        fragments.map((item) => t(item.key, item.values)).join(' · ');
}

/**
 * A card's game page (docs/frontend.md rule 13): below `lg` a full-screen
 * dialog over the catalog, from `lg` a 390px panel on the right. The scene,
 * the unit and the mode, the card's name, what the child does, «Səviyyə»,
 * «Əlavə» and a start bar stuck to the bottom. It opens with the
 * remembered choices that still apply (`openGame`; from «Bu sözlərlə
 * oyna», with own words on, `openWithOwnWords`) and forgets what was
 * changed when it closes without starting; a game that starts is
 * remembered by the server. A locked card's page says why, and «Başlat»
 * stays disabled.
 */
export function GameSheet({ card, onClose, ...props }: GameSheetProps) {
    return (
        <Dialog
            open={card !== null}
            onOpenChange={(open) => {
                if (!open) {
                    onClose();
                }
            }}
        >
            <DialogContent
                showCloseButton={false}
                aria-describedby={undefined}
                className="top-0 left-0 flex h-dvh max-h-dvh w-full max-w-none translate-x-0 translate-y-0 flex-col gap-0 overflow-hidden rounded-none border-0 p-0 sm:max-w-none lg:right-0 lg:left-auto lg:w-[390px] lg:border-l lg:data-[state=open]:zoom-in-100 lg:data-[state=open]:slide-in-from-right"
            >
                {card !== null && (
                    <GamePage key={card.id} card={card} {...props} />
                )}
            </DialogContent>
        </Dialog>
    );
}

function GamePage({
    card,
    ownWords,
    remembered,
    letters,
    letterCases,
    form,
    hidden,
    submitLabel,
    words,
    name,
    lettersHref,
}: Omit<GameSheetProps, 'card' | 'onClose'> & { card: CatalogCard }) {
    const { t } = useTranslation();
    const say = useLine();
    const summarise = useSummary();
    const [choice, setChoice] = useState<GameChoice | null>(() =>
        (ownWords ? openWithOwnWords : openGame)(card, remembered, letters),
    );
    const [shown, setShown] = useState<string | null>(null);
    const UnitIcon = UNIT_ICONS[card.card.unit];
    const ModeIcon = MODE_ICONS[card.game.mode];
    const level = choice === null ? undefined : chosenLevel(card, choice);
    const extras = choice === null ? null : gameExtras(card, choice);
    const fields = choice === null ? {} : postedFields(card, choice);
    const summary =
        level === undefined || extras === null
            ? ''
            : [level.level.title, summarise(startSummary(extras))]
                  .filter((part) => part !== '')
                  .join(' · ');

    const changeLetters = (code: string | null) =>
        lettersHref !== null &&
        isLettersShortage(code) && (
            <>
                {' '}
                <Link
                    href={lettersHref}
                    className="font-medium text-foreground underline underline-offset-4"
                >
                    {t('games.setup.locked.change')}
                </Link>
            </>
        );
    const shownLevel = card.levels.find(
        (item) => item.level.key === shown && !item.startable,
    );

    return (
        <Form
            {...form}
            options={{ preserveScroll: true, preserveState: true }}
            className="flex min-h-0 flex-1 flex-col"
        >
            {({ processing, errors }) => (
                <>
                    <HiddenFields fields={{ ...hidden, ...fields }} />

                    <div className="min-h-0 flex-1 overflow-y-auto">
                        <div className="relative">
                            <GameScene
                                wide
                                pack={card.pack}
                                unit={card.card.unit}
                                mechanic={card.card.mechanic}
                                sample={card.card.sample}
                                className={cn(
                                    !card.startable && 'opacity-45 grayscale',
                                )}
                            />
                            <DialogClose asChild>
                                <button
                                    type="button"
                                    aria-label={t('games.setup.back')}
                                    className="absolute top-3 left-3 grid size-[42px] place-items-center rounded-full bg-white/95 text-[#1d241f] shadow-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 lg:hidden"
                                >
                                    <ChevronLeft
                                        aria-hidden
                                        className="size-6"
                                    />
                                </button>
                            </DialogClose>
                            <DialogClose asChild>
                                <button
                                    type="button"
                                    aria-label={t('common.close')}
                                    className="absolute top-3 right-3 hidden size-[42px] place-items-center rounded-full bg-white/95 text-[#1d241f] shadow-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 lg:grid"
                                >
                                    <X aria-hidden className="size-5" />
                                </button>
                            </DialogClose>
                        </div>

                        <div className="flex flex-col gap-3.5 px-[18px] pt-4 pb-6">
                            <InputError
                                message={
                                    Object.entries(errors).find(([field]) =>
                                        Object.hasOwn(hidden, field),
                                    )?.[1]
                                }
                            />
                            <div className="flex flex-wrap gap-1.5">
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold">
                                    <UnitIcon
                                        aria-hidden
                                        className="size-3.5"
                                    />
                                    {t(`games.setup.units.${card.card.unit}`)}
                                </span>
                                <span className="inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-xs font-semibold">
                                    <ModeIcon
                                        aria-hidden
                                        className="size-3.5"
                                    />
                                    {t(`games.setup.modes.${card.game.mode}`)}
                                </span>
                            </div>
                            <DialogTitle className="text-2xl leading-tight font-bold tracking-tight text-balance">
                                {card.card.title}
                            </DialogTitle>
                            <DialogDescription className="-mt-1.5 text-sm text-muted-foreground">
                                {card.game.hint}
                            </DialogDescription>

                            {choice === null ? (
                                <p
                                    role="status"
                                    className="rounded-xl border border-dashed p-3 text-sm text-muted-foreground"
                                >
                                    <span className="font-medium text-foreground">
                                        {t('games.setup.locked.game')}
                                    </span>{' '}
                                    {card.drawable
                                        ? card.reason
                                        : t('games.setup.coming_soon')}
                                    {changeLetters(card.code)}
                                </p>
                            ) : (
                                <>
                                    {card.levels.length > 1 ? (
                                        <fieldset className="grid gap-1">
                                            <SetupLabel>
                                                {t('games.setup.level')}
                                            </SetupLabel>
                                            <div className="grid grid-cols-2 gap-2">
                                                {card.levels.map((item) => (
                                                    <LevelOption
                                                        key={item.level.key}
                                                        item={item}
                                                        chosen={
                                                            item.level.key ===
                                                            choice.level
                                                        }
                                                        line={
                                                            item.startable
                                                                ? item.level
                                                                      .hint
                                                                : say(
                                                                      lockedLine(
                                                                          item,
                                                                      ),
                                                                  )
                                                        }
                                                        onClick={() => {
                                                            setShown(
                                                                item.startable
                                                                    ? null
                                                                    : item.level
                                                                          .key,
                                                            );
                                                            setChoice(
                                                                chooseLevel(
                                                                    card,
                                                                    choice,
                                                                    item.level
                                                                        .key,
                                                                ),
                                                            );
                                                        }}
                                                    />
                                                ))}
                                            </div>
                                            <div aria-live="polite">
                                                {shownLevel !== undefined && (
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        <span className="font-medium text-foreground">
                                                            {
                                                                shownLevel.level
                                                                    .title
                                                            }
                                                        </span>
                                                        {' — '}
                                                        {shownLevel.reason}
                                                        {changeLetters(
                                                            shownLevel.code,
                                                        )}
                                                    </p>
                                                )}
                                            </div>
                                        </fieldset>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {level?.level.hint}
                                        </p>
                                    )}

                                    {extras !== null && (
                                        <SetupExtras
                                            summary={summarise(
                                                extrasSummary(extras),
                                            )}
                                            letterCase={extras.letterCase}
                                            letterCases={letterCases}
                                            onLetterCase={(letterCase) =>
                                                setChoice({
                                                    ...choice,
                                                    letterCase,
                                                })
                                            }
                                            letters={letters}
                                            asked={
                                                card.card.unit === 'letter'
                                                    ? new Set(choice.letters)
                                                    : null
                                            }
                                            onAsked={(asked) =>
                                                setChoice({
                                                    ...choice,
                                                    letters: letters
                                                        .filter((letter) =>
                                                            asked.has(
                                                                letter.lower,
                                                            ),
                                                        )
                                                        .map(
                                                            (letter) =>
                                                                letter.lower,
                                                        ),
                                                })
                                            }
                                            own={level?.option.own ?? null}
                                            ownWords={playsOwnWords(
                                                card,
                                                choice,
                                            )}
                                            onOwnWords={(ownWords) =>
                                                setChoice({
                                                    ...choice,
                                                    ownWords,
                                                })
                                            }
                                            words={words}
                                            defaultOpen={ownWords}
                                            errors={errors}
                                        />
                                    )}

                                    <p className="text-[13px] text-muted-foreground">
                                        {t(
                                            'games.setup.remembered_after_start',
                                            { name },
                                        )}
                                    </p>
                                </>
                            )}

                            <InputError
                                message={
                                    errors.level ??
                                    errors.mechanic ??
                                    errors.pack
                                }
                            />
                        </div>
                    </div>

                    <div className="flex shrink-0 flex-col gap-2 border-t bg-background/95 px-[18px] pt-3 pb-[calc(0.75rem+env(safe-area-inset-bottom))] backdrop-blur-sm">
                        {summary !== '' && (
                            <p className="truncate text-center text-sm text-muted-foreground">
                                {summary}
                            </p>
                        )}
                        <Button
                            type="submit"
                            size="lg"
                            disabled={choice === null || processing}
                            className="h-[52px] w-full rounded-xl text-base"
                        >
                            {processing && <Spinner />}
                            {submitLabel}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

/** A level of the card: its title over its hint; a locked one is dashed with a lock and never chosen. */
function LevelOption({
    item,
    chosen,
    line,
    onClick,
}: {
    item: CardLevel;
    chosen: boolean;
    line: string;
    onClick: () => void;
}) {
    const locked = !item.startable;

    return (
        <button
            type="button"
            aria-pressed={chosen}
            aria-disabled={locked || undefined}
            onClick={onClick}
            className={cn(
                'flex min-h-[58px] flex-col justify-center gap-0.5 rounded-[14px] border-[1.5px] px-3 py-2.5 text-left outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50',
                chosen
                    ? 'border-primary bg-accent ring-1 ring-primary'
                    : locked
                      ? 'cursor-default border-dashed text-muted-foreground'
                      : 'border-border hover:bg-accent/60',
            )}
        >
            <span className="flex items-center gap-1.5 text-sm font-semibold">
                {item.level.title}
                {locked && <Lock aria-hidden className="size-3.5 shrink-0" />}
            </span>
            <span className="text-xs text-muted-foreground">{line}</span>
        </button>
    );
}
