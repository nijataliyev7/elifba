import { Form, Link } from '@inertiajs/react';
import {
    Blocks,
    CaseSensitive,
    Hash,
    Image as ImageIcon,
    ListOrdered,
    MousePointerClick,
    Puzzle,
    Search,
    Text,
    WholeWord,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { useMemo, useState } from 'react';
import { SetupExtras } from '@/components/games/setup-extras';
import { SetupStart } from '@/components/games/setup-start';
import {
    SetupLabel,
    SetupQuestion,
    SetupRowButton,
    SetupTile,
} from '@/components/games/setup-tiles';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import {
    chooseLevel,
    chooseMechanic,
    choosePack,
    chooseUnit,
    extrasSummary,
    initialState,
    isLettersShortage,
    levelChoices,
    mechanicChoices,
    mechanicHint,
    mechanicName,
    packChoices,
    readsUnit,
    setupCatalog,
    startSummary,
    unitGroups,
} from '@/lib/setup-choices';
import type { ChoiceState, Extras, Fragment } from '@/lib/setup-choices';
import type {
    PlayersSetup,
    SetupRefusal,
    SetupUnit,
    SharedSetup,
} from '@/types';
import type { RouteFormDefinition } from '@/wayfinder';

export type GameSetupFormProps = SharedSetup &
    PlayersSetup & {
        /** Where the setup goes: the setup page's `StartGame`, or a guest board from the phone. */
        form: RouteFormDefinition<'post'>;
        /** Fields the form sends as they are (the class or the child, on a guest board); their errors show at the top. */
        hidden?: Record<string, string>;
        submitLabel?: string;
        /** A home game (ADR 011): the units played without reading come first. */
        home?: boolean;
        /** The setup page in the shell (a card from `lg`); otherwise the guest board on a phone. */
        page?: boolean;
        /** Who plays, for the card («1A sinfi», «Oynayır: Aysel»). */
        players?: string;
        /** The learned letters on the class's or the child's page; null on the guest board. */
        lettersHref?: string | null;
    };

const UNIT_ICONS: Record<SetupUnit, ComponentType<{ className?: string }>> = {
    letter: CaseSensitive,
    syllable: Blocks,
    word: WholeWord,
    sentence: Text,
    picture: ImageIcon,
    number: Hash,
};

const MECHANIC_ICONS: Record<string, ComponentType<{ className?: string }>> = {
    pop: MousePointerClick,
    find: Search,
    build_syllable: Puzzle,
    build_word: Puzzle,
    build_sentence: Puzzle,
    count: ListOrdered,
};

/**
 * The game setup (docs/frontend.md rule 13, architecture §2 "Game setup"):
 * three questions — what the children practise (the
 * unit and its level), how they play (the mechanic), which theme — then
 * «Əlavə» and the start bar. The choosing logic is `lib/setup-choices.ts`;
 * what can start is the server's `options`. The setup page and the phone's
 * guest board pages use it alike, for a class or a parent's child (`home`).
 */
export function GameSetupForm({
    packs,
    levels,
    options,
    letters,
    allLetters,
    letterCases,
    rounds,
    wordMaxLength,
    form,
    hidden = {},
    submitLabel,
    home = false,
    page = false,
    players,
    lettersHref = null,
}: GameSetupFormProps) {
    const { t } = useTranslation();
    const catalog = useMemo(
        () => setupCatalog(packs, levels, options, home),
        [packs, levels, options, home],
    );
    const [state, setState] = useState<ChoiceState | null>(() =>
        initialState(catalog),
    );
    const [lockedUnit, setLockedUnit] = useState<SetupUnit | null>(null);
    const [roundCount, setRoundCount] = useState(rounds.default);
    // The server's default (capitals for letters, as written otherwise)
    // until the adult picks one.
    const [chosenCase, setCase] = useState<'upper' | 'lower' | null>(null);
    const [asked, setAsked] = useState<Set<string>>(() => new Set());
    // «Yalnız mənim sözlərim» leads wherever the owner's words can start.
    const [chosenOwnWords, setOwnWords] = useState(true);

    const changeLetters =
        lettersHref === null ? null : (
            <Link
                href={lettersHref}
                className="font-medium text-foreground underline underline-offset-4"
            >
                {t('games.setup.locked.change')}
            </Link>
        );

    if (state === null) {
        return (
            <p className="rounded-lg border border-dashed p-4 text-sm text-muted-foreground">
                {t('games.setup.none')} {changeLetters}
            </p>
        );
    }

    const { choice } = state;
    const level = levels.find((item) => item.key === choice.level);
    const pack = packs.find((item) => item.key === choice.pack);
    const option = catalog.option(choice.level, choice.mechanic, choice.pack);
    const reads = readsUnit(choice.unit);
    const letterLevel = choice.unit === 'letter';
    const letterCase = chosenCase ?? (letterLevel ? 'upper' : 'lower');
    const own = option?.own ?? null;
    const ownWords = chosenOwnWords && own?.startable === true;
    const groups = unitGroups(catalog);
    const unitLevels = levelChoices(catalog, choice.unit);
    const mechanics = mechanicChoices(catalog, choice.level);
    const themes = packChoices(catalog, choice.level, choice.mechanic);
    const locked = groups
        .flatMap((group) => group.units)
        .find((item) => item.unit === lockedUnit && !item.startable);

    const extras: Extras = {
        rounds: roundCount,
        letterCase: reads ? letterCase : null,
        letters: letterLevel ? asked.size : null,
        ownWords: own?.startable === true ? ownWords : null,
    };
    const say = (fragments: Fragment[]) =>
        fragments.map((item) => t(item.key, item.values)).join(' · ');
    const game = t('games.setup.summary.game', {
        level: level?.title ?? '',
        mechanic: t(mechanicName(choice.mechanic, choice.unit)),
    });
    const details = [pack?.title ?? '', say(startSummary(extras))].join(' · ');

    /** A short reason on a locked unit or level: «Daha çox hərf lazımdır» for a learned-letters shortage. */
    const shortReason = (status: SetupRefusal) =>
        isLettersShortage(status.code)
            ? t('games.setup.locked.letters')
            : status.reason;
    /** The full reason, with «Hərfləri dəyiş» after a learned-letters shortage. */
    const fullReason = (status: SetupRefusal) => (
        <>
            {status.reason}
            {isLettersShortage(status.code) && changeLetters !== null && (
                <> {changeLetters}</>
            )}
        </>
    );

    const update = (next: ChoiceState) => {
        setState(next);
        setLockedUnit(null);
    };
    const mechanicTitle = (key: string) => t(mechanicName(key, choice.unit));
    const packTitle = (key: string) =>
        packs.find((item) => item.key === key)?.title ?? key;

    return (
        <Form
            {...form}
            className={
                page
                    ? 'flex flex-col lg:grid lg:grid-cols-[minmax(0,720px)_320px] lg:items-start lg:gap-8'
                    : 'flex flex-col'
            }
        >
            {({ processing, errors }) => (
                <>
                    {Object.entries(hidden).map(([name, value]) => (
                        <input
                            key={name}
                            type="hidden"
                            name={name}
                            value={value}
                        />
                    ))}
                    <input type="hidden" name="level" value={choice.level} />
                    <input
                        type="hidden"
                        name="mechanic"
                        value={choice.mechanic}
                    />
                    <input type="hidden" name="pack" value={choice.pack} />
                    <input type="hidden" name="rounds" value={roundCount} />
                    {reads && (
                        <input
                            type="hidden"
                            name="letter_case"
                            value={letterCase}
                        />
                    )}
                    {letterLevel &&
                        letters
                            .filter((letter) => asked.has(letter.lower))
                            .map((letter) => (
                                <input
                                    key={letter.lower}
                                    type="hidden"
                                    name="letters[]"
                                    value={letter.lower}
                                />
                            ))}
                    {ownWords && (
                        <input type="hidden" name="own_words" value="1" />
                    )}

                    <div className="flex min-w-0 flex-col gap-10">
                        {Object.keys(errors).some((name) =>
                            Object.hasOwn(hidden, name),
                        ) && (
                            <InputError
                                message={
                                    Object.entries(errors).find(([name]) =>
                                        Object.hasOwn(hidden, name),
                                    )?.[1]
                                }
                            />
                        )}

                        <SetupQuestion number={1} title={t('games.setup.unit')}>
                            <div className="grid gap-5">
                                {groups.map((group) => (
                                    <div key={group.key} className="grid gap-2">
                                        <p className="text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                            {t(
                                                `games.setup.groups.${group.key}`,
                                            )}
                                        </p>
                                        <div className="grid grid-cols-2 gap-2 lg:grid-cols-3">
                                            {group.units.map((item) => (
                                                <SetupTile
                                                    key={item.unit}
                                                    chosen={
                                                        item.unit ===
                                                        choice.unit
                                                    }
                                                    locked={!item.startable}
                                                    icon={UNIT_ICONS[item.unit]}
                                                    title={t(
                                                        `games.setup.units.${item.unit}`,
                                                    )}
                                                    line={
                                                        item.startable
                                                            ? t(
                                                                  `games.setup.unit_hints.${item.unit}`,
                                                              )
                                                            : shortReason(item)
                                                    }
                                                    onClick={() =>
                                                        item.startable
                                                            ? update(
                                                                  chooseUnit(
                                                                      catalog,
                                                                      state,
                                                                      item.unit,
                                                                  ),
                                                              )
                                                            : setLockedUnit(
                                                                  item.unit,
                                                              )
                                                    }
                                                />
                                            ))}
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <div aria-live="polite">
                                {locked !== undefined && (
                                    <p className="rounded-lg border border-dashed p-3 text-sm text-muted-foreground">
                                        <span className="font-medium text-foreground">
                                            {t('games.setup.locked.unit', {
                                                unit: t(
                                                    `games.setup.units.${locked.unit}`,
                                                ),
                                            })}
                                        </span>{' '}
                                        {fullReason(locked)}
                                    </p>
                                )}
                            </div>

                            <fieldset className="mt-2 grid gap-1">
                                <SetupLabel>
                                    {t('games.setup.level')}
                                </SetupLabel>
                                {unitLevels.length > 1 ? (
                                    <div className="grid grid-cols-2 gap-2 sm:flex sm:flex-wrap">
                                        {unitLevels.map((item) => (
                                            <SetupRowButton
                                                key={item.level.key}
                                                chosen={
                                                    item.level.key ===
                                                    choice.level
                                                }
                                                locked={!item.startable}
                                                className="h-auto flex-row justify-start py-2 text-left"
                                                onClick={() =>
                                                    update(
                                                        chooseLevel(
                                                            catalog,
                                                            state,
                                                            item.level.key,
                                                        ),
                                                    )
                                                }
                                            >
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span>
                                                        {item.level.title}
                                                    </span>
                                                    <span className="text-xs font-normal opacity-80">
                                                        {item.startable
                                                            ? item.level.hint
                                                            : shortReason(item)}
                                                    </span>
                                                </span>
                                            </SetupRowButton>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {level?.hint}
                                    </p>
                                )}
                                {unitLevels
                                    .filter(
                                        (item) =>
                                            !item.startable &&
                                            isLettersShortage(item.code),
                                    )
                                    .map((item) => (
                                        <p
                                            key={item.level.key}
                                            className="text-sm text-muted-foreground"
                                        >
                                            <span className="font-medium text-foreground">
                                                {item.level.title}
                                            </span>
                                            {' — '}
                                            {fullReason(item)}
                                        </p>
                                    ))}
                                <InputError message={errors.level} />
                            </fieldset>

                            {reads && (
                                <div className="grid gap-1 text-sm text-muted-foreground">
                                    <p>
                                        {allLetters
                                            ? t('games.setup.learned.all')
                                            : t('games.setup.learned.some', {
                                                  count: letters.length,
                                              })}
                                    </p>
                                    {(!allLetters || lettersHref !== null) && (
                                        <p className="flex flex-wrap items-baseline gap-x-3">
                                            {!allLetters && (
                                                <span
                                                    lang="az"
                                                    className="font-board text-base tracking-[0.2em] text-foreground"
                                                >
                                                    {letters
                                                        .map(
                                                            (letter) =>
                                                                letter.upper,
                                                        )
                                                        .join(' ')}
                                                </span>
                                            )}
                                            {lettersHref !== null && (
                                                <Link
                                                    href={lettersHref}
                                                    className="font-medium text-foreground underline underline-offset-4"
                                                >
                                                    {t(
                                                        'games.setup.learned.change',
                                                    )}
                                                </Link>
                                            )}
                                        </p>
                                    )}
                                </div>
                            )}
                        </SetupQuestion>

                        <SetupQuestion
                            number={2}
                            title={t('games.setup.mechanic')}
                        >
                            <div className="grid grid-cols-1 gap-2 lg:grid-cols-3">
                                {mechanics.map((item) => (
                                    <SetupTile
                                        key={item.mechanic}
                                        only={mechanics.length === 1}
                                        chosen={
                                            item.mechanic === choice.mechanic
                                        }
                                        locked={!item.startable}
                                        icon={
                                            MECHANIC_ICONS[item.mechanic] ??
                                            Puzzle
                                        }
                                        title={mechanicTitle(item.mechanic)}
                                        line={
                                            item.startable
                                                ? t(
                                                      mechanicHint(
                                                          item.mechanic,
                                                          choice.unit,
                                                      ),
                                                  )
                                                : item.reason
                                        }
                                        onClick={() =>
                                            update(
                                                chooseMechanic(
                                                    catalog,
                                                    state,
                                                    item.mechanic,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                            </div>
                            <div aria-live="polite">
                                {state.replaced.mechanic !== null && (
                                    <p className="rounded-lg bg-accent px-3 py-2 text-sm">
                                        {t('games.setup.replaced.mechanic', {
                                            old: mechanicTitle(
                                                state.replaced.mechanic.from,
                                            ),
                                            new: mechanicTitle(
                                                state.replaced.mechanic.to,
                                            ),
                                        })}
                                    </p>
                                )}
                            </div>
                            <InputError message={errors.mechanic} />
                        </SetupQuestion>

                        <SetupQuestion number={3} title={t('games.setup.pack')}>
                            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-4">
                                {themes.map((item) => (
                                    <SetupTile
                                        key={item.pack.key}
                                        stacked
                                        chosen={item.pack.key === choice.pack}
                                        locked={!item.startable}
                                        picture={item.pack.cover}
                                        title={item.pack.title}
                                        line={
                                            item.startable ? undefined : !item.drawable ? (
                                                <Badge variant="secondary">
                                                    {t(
                                                        'games.setup.coming_soon',
                                                    )}
                                                </Badge>
                                            ) : (
                                                item.reason
                                            )
                                        }
                                        onClick={() =>
                                            update(
                                                choosePack(
                                                    catalog,
                                                    state,
                                                    item.pack.key,
                                                ),
                                            )
                                        }
                                    />
                                ))}
                            </div>
                            <div aria-live="polite">
                                {state.replaced.pack !== null && (
                                    <p className="rounded-lg bg-accent px-3 py-2 text-sm">
                                        {t('games.setup.replaced.pack', {
                                            old: packTitle(
                                                state.replaced.pack.from,
                                            ),
                                            new: packTitle(
                                                state.replaced.pack.to,
                                            ),
                                        })}
                                    </p>
                                )}
                            </div>
                            <InputError message={errors.pack} />
                        </SetupQuestion>

                        <SetupExtras
                            summary={say(extrasSummary(extras))}
                            rounds={rounds}
                            roundCount={roundCount}
                            onRounds={setRoundCount}
                            letterCase={reads ? letterCase : null}
                            letterCases={letterCases}
                            onLetterCase={setCase}
                            letters={letters}
                            asked={letterLevel ? asked : null}
                            onAsked={setAsked}
                            own={own}
                            ownWords={ownWords}
                            onOwnWords={setOwnWords}
                            teacher={!home}
                            wordMaxLength={wordMaxLength}
                            errors={errors}
                        />
                    </div>

                    <SetupStart
                        cover={pack?.cover ?? ''}
                        game={game}
                        details={details}
                        players={players}
                        label={submitLabel ?? t('games.setup.start')}
                        processing={processing}
                        page={page}
                    />
                </>
            )}
        </Form>
    );
}
