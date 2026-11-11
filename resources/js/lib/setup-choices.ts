import type {
    SetupLevel,
    SetupOption,
    SetupPack,
    SetupRefusal,
    SetupUnit,
} from '@/types';

/*
 * The game setup's choosing logic (docs/frontend.md rule 13), as pure
 * functions: the form only renders what these return. The adult answers
 * three questions in order — the unit and its level, the mechanic, the
 * theme — and a question filters only the questions after it. What can
 * start is always the server's answer (`options[].startable`, the check
 * `GameSetupData::rules()` makes); this module orders it, picks defaults,
 * keeps what still starts after a change and says why a choice is locked.
 */

/** The units a child reads (`Unit::reads()`); the others (pictures, numbers) are played without reading. */
const READING_UNITS: readonly string[] = [
    'letter',
    'syllable',
    'word',
    'sentence',
];

/**
 * The reasons that mean "learn more letters" (`games.setup.errors.*`): a
 * locked unit or level then says «Daha çox hərf lazımdır» and links to the
 * learned letters.
 */
const LETTER_SHORTAGES: readonly string[] = [
    'too_few_letters',
    'too_few_syllables',
    'too_few_words',
    'too_few_sentences',
];

/** Whether a child reads the items of a level of this unit (`Unit::reads()`); an unknown unit counts as read. */
export function readsUnit(unit: string | null): boolean {
    return unit === null || READING_UNITS.includes(unit);
}

/** Whether a refusal is a learned-letters shortage. */
export function isLettersShortage(code: string | null): boolean {
    return code !== null && LETTER_SHORTAGES.includes(code);
}

/** The key of a mechanic's name: `pop` is «De və vur» where nothing is read. */
export function mechanicName(mechanic: string, unit: string | null): string {
    return mechanic === 'pop' && !readsUnit(unit)
        ? 'games.mechanics.pop_say'
        : `games.mechanics.${mechanic}`;
}

/** The key of the line under a mechanic: what the child does. */
export function mechanicHint(mechanic: string, unit: string | null): string {
    return mechanic === 'pop' && !readsUnit(unit)
        ? 'games.setup.mechanic_hints.pop_say'
        : `games.setup.mechanic_hints.${mechanic}`;
}

export type GroupKey = 'reading' | 'pictures';

/** Whether a choice can start, and if not the server's reason and its code. */
export type Status = SetupRefusal;

export type LevelChoice = Status & { level: SetupLevel };

export type UnitChoice = Status & { unit: SetupUnit; levels: LevelChoice[] };

export type UnitGroup = { key: GroupKey; units: UnitChoice[] };

export type MechanicChoice = Status & { mechanic: string };

export type PackChoice = Status & { pack: SetupPack; drawable: boolean };

/** The server's offer, indexed once per page. */
export type Catalog = {
    packs: readonly SetupPack[];
    levels: readonly SetupLevel[];
    /** A home game (ADR 011): the units played without reading come first. */
    home: boolean;
    option: (
        level: string,
        mechanic: string,
        pack: string,
    ) => SetupOption | undefined;
};

export function setupCatalog(
    packs: readonly SetupPack[],
    levels: readonly SetupLevel[],
    options: readonly SetupOption[],
    home: boolean,
): Catalog {
    const index = new Map(
        options.map((option) => [
            `${option.level}|${option.mechanic}|${option.pack}`,
            option,
        ]),
    );

    return {
        packs,
        levels,
        home,
        option: (level, mechanic, pack) =>
            index.get(`${level}|${mechanic}|${pack}`),
    };
}

const STARTABLE: Status = { startable: true, reason: null, code: null };

/**
 * One status for several options in the content's order: startable when
 * any is; otherwise the first one's reason.
 */
function statusOf(statuses: readonly Status[]): Status | null {
    if (statuses.length === 0) {
        return null;
    }

    if (statuses.some((status) => status.startable)) {
        return STARTABLE;
    }

    const { reason, code } = statuses[0];

    return { startable: false, reason, code };
}

function definedOnly<T>(items: (T | undefined)[]): T[] {
    return items.filter((item): item is T => item !== undefined);
}

/** A level's options, by mechanic in the level's order, then by pack in the content's order. */
function levelOptions(
    catalog: Catalog,
    level: SetupLevel,
    mechanic?: string,
): SetupOption[] {
    return (mechanic === undefined ? level.mechanics : [mechanic]).flatMap(
        (item) =>
            definedOnly(
                catalog.packs.map((pack) =>
                    catalog.option(level.key, item, pack.key),
                ),
            ),
    );
}

function findLevel(catalog: Catalog, key: string): SetupLevel | undefined {
    return catalog.levels.find((level) => level.key === key);
}

/** The levels of a unit that have an option, in the content's order. */
export function levelChoices(catalog: Catalog, unit: string): LevelChoice[] {
    return definedOnly(
        catalog.levels
            .filter((level) => level.unit === unit)
            .map((level) => {
                const status = statusOf(levelOptions(catalog, level));

                return status === null ? undefined : { ...status, level };
            }),
    );
}

/**
 * The units in two groups, «Oxu» and «Oxumadan»: reading first for a
 * class, the others first at home; within a group, the content's order.
 * A unit shows when one of its levels has an option. A unit that cannot
 * start anywhere carries its first level's reason.
 */
export function unitGroups(catalog: Catalog): UnitGroup[] {
    const units = [
        ...new Set(catalog.levels.map((level) => level.unit)),
    ].flatMap((unit): UnitChoice[] => {
        const levels = levelChoices(catalog, unit);
        const status = statusOf(levels);

        return status === null ? [] : [{ ...status, unit, levels }];
    });
    const order: GroupKey[] = catalog.home
        ? ['pictures', 'reading']
        : ['reading', 'pictures'];

    return order
        .map((key) => ({
            key,
            units: units.filter(
                (choice) => readsUnit(choice.unit) === (key === 'reading'),
            ),
        }))
        .filter((group) => group.units.length > 0);
}

/** The mechanics of a level that have an option, in the level's order; a locked one carries its first theme's reason. */
export function mechanicChoices(
    catalog: Catalog,
    levelKey: string,
): MechanicChoice[] {
    const level = findLevel(catalog, levelKey);

    if (level === undefined) {
        return [];
    }

    return level.mechanics.flatMap((mechanic) => {
        const status = statusOf(levelOptions(catalog, level, mechanic));

        return status === null ? [] : [{ ...status, mechanic }];
    });
}

/** The themes that have an option for this level and mechanic, in the content's order. */
export function packChoices(
    catalog: Catalog,
    levelKey: string,
    mechanic: string,
): PackChoice[] {
    return catalog.packs.flatMap((pack) => {
        const option = catalog.option(levelKey, mechanic, pack.key);

        return option === undefined
            ? []
            : [
                  {
                      pack,
                      drawable: option.drawable,
                      startable: option.startable,
                      reason: option.reason,
                      code: option.code,
                  },
              ];
    });
}

/** The answers to the three questions. */
export type Choice = {
    unit: SetupUnit;
    level: string;
    mechanic: string;
    pack: string;
};

/** A choice the adult had picked that a change replaced: the old key and the new one. */
export type Replacement = { from: string; to: string };

export type ChoiceState = {
    choice: Choice;
    /** Whether the adult picked the mechanic and the theme (not the form's default). */
    picked: { mechanic: boolean; pack: boolean };
    /** What the last change replaced of the adult's picks, shown until the next choice. */
    replaced: { mechanic: Replacement | null; pack: Replacement | null };
};

function firstStartable<T extends Status>(
    choices: readonly T[],
): T | undefined {
    return choices.find((choice) => choice.startable);
}

/**
 * Fills the choices after the level: the mechanic (`mechanic`, which the
 * adult picked when `mechanicPicked`) and the theme stay when they can
 * still start, and are replaced by the first that can otherwise. A
 * replaced pick of the adult's is reported; a replaced choice is gone and
 * never comes back by itself.
 */
function settle(
    catalog: Catalog,
    state: ChoiceState,
    next: { unit: SetupUnit; level: string; mechanic: string },
    mechanicPicked: boolean,
): ChoiceState {
    const mechanics = mechanicChoices(catalog, next.level);
    const keepMechanic = mechanics.some(
        (item) => item.mechanic === next.mechanic && item.startable,
    );
    const mechanic = keepMechanic
        ? next.mechanic
        : (firstStartable(mechanics)?.mechanic ?? next.mechanic);
    const packs = packChoices(catalog, next.level, mechanic);
    const keepPack = packs.some(
        (item) => item.pack.key === state.choice.pack && item.startable,
    );
    const pack = keepPack
        ? state.choice.pack
        : (firstStartable(packs)?.pack.key ?? state.choice.pack);

    return {
        choice: { unit: next.unit, level: next.level, mechanic, pack },
        picked: {
            mechanic: keepMechanic && mechanicPicked,
            pack: keepPack && state.picked.pack,
        },
        replaced: {
            mechanic:
                !keepMechanic && mechanicPicked
                    ? { from: next.mechanic, to: mechanic }
                    : null,
            pack:
                !keepPack && state.picked.pack
                    ? { from: state.choice.pack, to: pack }
                    : null,
        },
    };
}

/**
 * The page's opening choice: the first unit, in the page's group order,
 * with a level that can start; its first such level; that level's first
 * mechanic that can start; the first theme that can start with them. Null
 * when nothing can start at all.
 */
export function initialState(catalog: Catalog): ChoiceState | null {
    const unit = unitGroups(catalog)
        .flatMap((group) => group.units)
        .find((item) => item.startable);
    const level = unit === undefined ? undefined : firstStartable(unit.levels);

    if (unit === undefined || level === undefined) {
        return null;
    }

    const mechanic = firstStartable(mechanicChoices(catalog, level.level.key));
    const pack =
        mechanic === undefined
            ? undefined
            : firstStartable(
                  packChoices(catalog, level.level.key, mechanic.mechanic),
              );

    if (mechanic === undefined || pack === undefined) {
        return null;
    }

    return {
        choice: {
            unit: unit.unit,
            level: level.level.key,
            mechanic: mechanic.mechanic,
            pack: pack.pack.key,
        },
        picked: { mechanic: false, pack: false },
        replaced: { mechanic: null, pack: null },
    };
}

/** A unit: its first level that can start. A locked unit is never chosen; the chosen one again changes nothing. */
export function chooseUnit(
    catalog: Catalog,
    state: ChoiceState,
    unit: SetupUnit,
): ChoiceState {
    const level = firstStartable(levelChoices(catalog, unit));

    if (level === undefined || unit === state.choice.unit) {
        return state;
    }

    return settle(
        catalog,
        state,
        { unit, level: level.level.key, mechanic: state.choice.mechanic },
        state.picked.mechanic,
    );
}

/** A level of the chosen unit; the mechanic and the theme stay where they still start. */
export function chooseLevel(
    catalog: Catalog,
    state: ChoiceState,
    levelKey: string,
): ChoiceState {
    const level = levelChoices(catalog, state.choice.unit).find(
        (item) => item.level.key === levelKey,
    );

    if (level === undefined || !level.startable) {
        return state;
    }

    return settle(
        catalog,
        state,
        { ...state.choice, level: levelKey },
        state.picked.mechanic,
    );
}

/** A mechanic of the chosen level; the theme stays where it still starts. */
export function chooseMechanic(
    catalog: Catalog,
    state: ChoiceState,
    mechanic: string,
): ChoiceState {
    const item = mechanicChoices(catalog, state.choice.level).find(
        (choice) => choice.mechanic === mechanic,
    );

    if (item === undefined || !item.startable) {
        return state;
    }

    return settle(catalog, state, { ...state.choice, mechanic }, true);
}

/** A theme that can start with the level and the mechanic. */
export function choosePack(
    catalog: Catalog,
    state: ChoiceState,
    pack: string,
): ChoiceState {
    const item = packChoices(
        catalog,
        state.choice.level,
        state.choice.mechanic,
    ).find((choice) => choice.pack.key === pack);

    if (item === undefined || !item.startable) {
        return state;
    }

    return {
        choice: { ...state.choice, pack },
        picked: { ...state.picked, pack: true },
        replaced: { mechanic: null, pack: null },
    };
}

/** The round counts to choose from: every multiple of 5 from min to max, plus the current count (the configured default) when it is none of them. */
export function roundSteps(
    rounds: { min: number; max: number },
    current: number,
): number[] {
    const steps: number[] = [];

    for (
        let count = Math.ceil(rounds.min / 5) * 5;
        count <= rounds.max;
        count += 5
    ) {
        steps.push(count);
    }

    if (
        !steps.includes(current) &&
        current >= rounds.min &&
        current <= rounds.max
    ) {
        steps.push(current);
        steps.sort((a, b) => a - b);
    }

    return steps;
}

/** The extras as the summaries name them; null where a choice does not apply. */
export type Extras = {
    rounds: number;
    /** Null at a level that does not read. */
    letterCase: 'upper' | 'lower' | null;
    /** How many letters are chosen (0 = all); null outside the letter level. */
    letters: number | null;
    /** Whether only the owner's words play; null where it is not offered or cannot start. */
    ownWords: boolean | null;
};

export type Fragment = { key: string; values?: Record<string, number> };

/**
 * The muted values beside «Əlavə»: «10 raund · böyük hərflər · bütün
 * hərflər», every extra that applies, as translation keys.
 */
export function extrasSummary(extras: Extras): Fragment[] {
    return [
        { key: 'games.board.rounds', values: { count: extras.rounds } },
        ...(extras.letterCase === null
            ? []
            : [{ key: `games.setup.summary.${extras.letterCase}` }]),
        ...(extras.letters === null
            ? []
            : [
                  extras.letters === 0
                      ? { key: 'games.setup.summary.all_letters' }
                      : {
                            key: 'games.setup.summary.letters',
                            values: { count: extras.letters },
                        },
              ]),
        ...(extras.ownWords === null
            ? []
            : [
                  {
                      key: extras.ownWords
                          ? 'games.setup.summary.own_words'
                          : 'games.setup.summary.all_words',
                  },
              ]),
    ];
}

/**
 * The start bar's second line after the theme: the rounds, the letter case
 * where it applies, the chosen letters when there are some, and «yalnız
 * mənim sözlərim» when on.
 */
export function startSummary(extras: Extras): Fragment[] {
    return extrasSummary(extras).filter(
        (fragment) =>
            fragment.key !== 'games.setup.summary.all_letters' &&
            fragment.key !== 'games.setup.summary.all_words',
    );
}
