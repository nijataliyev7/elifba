import type {
    Letter,
    RememberedSetup,
    SetupCard,
    SetupGame,
    SetupLevel,
    SetupMode,
    SetupOption,
    SetupPack,
    SetupRefusal,
    SetupUnit,
} from '@/types';

/*
 * The game catalog's choosing logic (docs/frontend.md rule 13), as pure
 * functions: the components only render what these return. «Yeni oyun» is
 * a catalog of ready games: one card per theme and game, on one shelf per
 * unit, filtered by the mode («Asan»: `pop`; «Normal»: the rest). A card
 * covers every level of its unit that lists its mechanic; its game page
 * chooses the level and «Əlavə». What can start is always the server's
 * answer (`options[].startable`, the check `GameSetupData::rules()` makes);
 * this module orders it, says why a card is locked and opens a game page
 * with the remembered choices that still apply.
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
 * locked card or level then says «Daha çox hərf lazımdır» and links to the
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

/** Whether a choice can start, and if not the server's reason and its code. */
export type Status = SetupRefusal;

/** One level of a card, with whether it starts with this card's game and pack. */
export type CardLevel = Status & { level: SetupLevel; option: SetupOption };

/** A card as the catalog shows it: the server's card with its game, theme, levels and status. */
export type CatalogCard = Status & {
    /** `unit.mechanic.pack`, unique on the page. */
    id: string;
    card: SetupCard;
    game: SetupGame;
    pack: SetupPack;
    /** False when the board cannot draw this game in this theme yet («Tezliklə»). */
    drawable: boolean;
    /** The levels of its unit that list its mechanic, in the content's order. */
    levels: CardLevel[];
};

/** One shelf: the cards of one unit in the chosen mode. */
export type Shelf = { unit: SetupUnit; cards: CatalogCard[] };

/** A text to show: a translation key with its values, or the server's own text. */
export type Line =
    | { key: string; values?: Record<string, number | string> }
    | { text: string };

const STARTABLE: Status = { startable: true, reason: null, code: null };

/** Startable when any is; otherwise the first one's reason. */
function statusOf(statuses: readonly [Status, ...Status[]]): Status {
    if (statuses.some((status) => status.startable)) {
        return STARTABLE;
    }

    const { reason, code } = statuses[0];

    return { startable: false, reason, code };
}

function firstStartable<T extends Status>(
    choices: readonly T[],
): T | undefined {
    return choices.find((choice) => choice.startable);
}

/**
 * The server's cards with what the catalog needs of each: its game, its
 * theme, its levels (each with its option) and its status — startable when
 * one of its levels is, else its first level's reason. A card without a
 * game, a theme or an option is dropped.
 */
export function catalogCards(
    setup: {
        packs: readonly SetupPack[];
        levels: readonly SetupLevel[];
        games: readonly SetupGame[];
    },
    players: {
        cards: readonly SetupCard[];
        options: readonly SetupOption[];
    },
): CatalogCard[] {
    const options = new Map(
        players.options.map((option) => [
            `${option.level}|${option.mechanic}|${option.pack}`,
            option,
        ]),
    );

    return players.cards.flatMap((card): CatalogCard[] => {
        const game = setup.games.find(
            (item) =>
                item.unit === card.unit && item.mechanic === card.mechanic,
        );
        const pack = setup.packs.find((item) => item.key === card.pack);
        const levels = setup.levels.flatMap((level): CardLevel[] => {
            const option =
                level.unit === card.unit &&
                level.mechanics.includes(card.mechanic)
                    ? options.get(`${level.key}|${card.mechanic}|${card.pack}`)
                    : undefined;

            return option === undefined
                ? []
                : [
                      {
                          level,
                          option,
                          startable: option.startable,
                          reason: option.reason,
                          code: option.code,
                      },
                  ];
        });

        const [first, ...rest] = levels;

        if (game === undefined || pack === undefined || first === undefined) {
            return [];
        }

        return [
            {
                ...statusOf([first, ...rest]),
                id: `${card.unit}.${card.mechanic}.${card.pack}`,
                card,
                game,
                pack,
                drawable: first.option.drawable,
                levels,
            },
        ];
    });
}

/**
 * The shelves of a mode: one per unit with a card in it, the reading units
 * first for a class and the picture and number units first at home, each
 * group in the content's order. Within a shelf, the game order (the level's
 * mechanics, `pop` first), then the packs' order, every card that can
 * start before every locked one.
 */
export function shelves(
    cards: readonly CatalogCard[],
    mode: SetupMode,
    home: boolean,
): Shelf[] {
    const shown = cards.filter((card) => card.game.mode === mode);
    const units = [...new Set(shown.map((card) => card.card.unit))];
    const order = [
        ...units.filter((unit) => readsUnit(unit) !== home),
        ...units.filter((unit) => readsUnit(unit) === home),
    ];

    return order.map((unit) => {
        const own = shown.filter((card) => card.card.unit === unit);

        return {
            unit,
            cards: [
                ...own.filter((card) => card.startable),
                ...own.filter((card) => !card.startable),
            ],
        };
    });
}

/** «Hazır oyun»: the first card that can start, in page order. */
export function readyCard(
    shelvesShown: readonly Shelf[],
): CatalogCard | undefined {
    return shelvesShown
        .flatMap((shelf) => shelf.cards)
        .find((card) => card.startable);
}

/** Whether any game at all can start, in either mode. */
export function anyStartable(cards: readonly CatalogCard[]): boolean {
    return cards.some((card) => card.startable);
}

/**
 * The muted line under a card's name: «:count səviyyə» when more than one
 * of its levels can start, else that level's hint; on a locked card
 * «Tezliklə» when the board cannot draw it, «Daha çox hərf lazımdır» for a
 * learned-letters shortage, else the server's reason.
 */
export function cardLine(card: CatalogCard): Line {
    if (!card.startable) {
        return lockedLine(card);
    }

    const startable = card.levels.filter((level) => level.startable);

    return startable.length > 1
        ? {
              key: 'games.setup.level_count',
              values: { count: startable.length },
          }
        : { text: startable[0].level.hint };
}

/** What a locked card or level says in a line. */
export function lockedLine(status: Status & { drawable?: boolean }): Line {
    if (status.drawable === false) {
        return { key: 'games.setup.coming_soon' };
    }

    return isLettersShortage(status.code)
        ? { key: 'games.setup.locked.letters' }
        : { text: status.reason ?? '' };
}

/** What a game page sends, besides its card: the level and «Əlavə». */
export type GameChoice = {
    level: string;
    letterCase: 'upper' | 'lower';
    /** The letters asked for at the letter level (lowercase); empty = all. */
    letters: string[];
    /** «Yalnız mənim sözlərim» as chosen; it plays only where it can start. */
    ownWords: boolean;
};

/** The server's default case: capitals at the letter level, as written otherwise. */
function defaultCase(unit: SetupUnit): 'upper' | 'lower' {
    return unit === 'letter' ? 'upper' : 'lower';
}

/**
 * A card's level on opening: the remembered level of its unit when it can
 * start with this card, else the first that can; undefined when none can.
 */
export function defaultLevel(
    card: CatalogCard,
    remembered: RememberedSetup,
): CardLevel | undefined {
    const wanted = remembered.levels[card.card.unit];

    return (
        card.levels.find(
            (level) => level.startable && level.level.key === wanted,
        ) ?? firstStartable(card.levels)
    );
}

/**
 * A game page as it opens: the default level; the remembered letter case
 * of its unit, else the server's default; the remembered letters still
 * learned (the server drops them below two) at the letter level; the
 * remembered «Yalnız mənim sözlərim», else on. Null for a locked card.
 */
export function openGame(
    card: CatalogCard,
    remembered: RememberedSetup,
    learned: readonly Letter[],
): GameChoice | null {
    const level = defaultLevel(card, remembered);

    if (level === undefined) {
        return null;
    }

    const unit = card.card.unit;
    const letters =
        unit === 'letter'
            ? (remembered.letters ?? []).filter((letter) =>
                  learned.some((item) => item.lower === letter),
              )
            : [];

    return {
        level: level.level.key,
        letterCase: remembered.letterCases[unit] ?? defaultCase(unit),
        letters: letters.length >= 2 ? letters : [],
        ownWords: remembered.ownWords ?? true,
    };
}

/** The card's level a choice names. */
export function chosenLevel(
    card: CatalogCard,
    choice: GameChoice,
): CardLevel | undefined {
    return card.levels.find((level) => level.level.key === choice.level);
}

/** Another level of the card; a locked one is never chosen. */
export function chooseLevel(
    card: CatalogCard,
    choice: GameChoice,
    key: string,
): GameChoice {
    const level = card.levels.find((item) => item.level.key === key);

    return level === undefined || !level.startable
        ? choice
        : { ...choice, level: key };
}

/** Whether only the owner's words play: chosen, and startable at the chosen level. */
export function playsOwnWords(card: CatalogCard, choice: GameChoice): boolean {
    return (
        choice.ownWords &&
        chosenLevel(card, choice)?.option.own?.startable === true
    );
}

/**
 * What the game page posts (`GameSetupData::rules()`): the card's
 * mechanic and pack, the level, the letter case where the unit reads, the
 * letters asked for at the letter level and `own_words` when only the
 * owner's words play. No `rounds`: the server's default.
 */
export function postedFields(
    card: CatalogCard,
    choice: GameChoice,
): Record<string, string | string[]> {
    const unit = card.card.unit;

    return {
        mechanic: card.card.mechanic,
        pack: card.card.pack,
        level: choice.level,
        ...(readsUnit(unit) ? { letter_case: choice.letterCase } : {}),
        ...(unit === 'letter' && choice.letters.length > 0
            ? { letters: choice.letters }
            : {}),
        ...(playsOwnWords(card, choice) ? { own_words: '1' } : {}),
    };
}

/** The extras as the summaries name them; null where a choice does not apply. */
export type Extras = {
    /** Null at a level that does not read. */
    letterCase: 'upper' | 'lower' | null;
    /** How many letters are chosen (0 = all); null outside the letter level. */
    letters: number | null;
    /** Whether only the owner's words play; null where it is not offered or cannot start. */
    ownWords: boolean | null;
};

/** A game page's extras, for its summaries. */
export function gameExtras(card: CatalogCard, choice: GameChoice): Extras {
    const unit = card.card.unit;
    const own = chosenLevel(card, choice)?.option.own ?? null;

    return {
        letterCase: readsUnit(unit) ? choice.letterCase : null,
        letters: unit === 'letter' ? choice.letters.length : null,
        ownWords: own?.startable === true ? playsOwnWords(card, choice) : null,
    };
}

export type Fragment = { key: string; values?: Record<string, number> };

/**
 * The muted values beside «Əlavə»: «böyük hərflər · bütün hərflər», every
 * extra that applies, as translation keys (the round count is the board's).
 */
export function extrasSummary(extras: Extras): Fragment[] {
    return [
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
 * The summary after the level's title, on a game page's start bar and on
 * «Hazır oyun»: the letter case where it applies, the chosen letters when
 * there are some, and «yalnız mənim sözlərim» when on.
 */
export function startSummary(extras: Extras): Fragment[] {
    return extrasSummary(extras).filter(
        (fragment) =>
            fragment.key !== 'games.setup.summary.all_letters' &&
            fragment.key !== 'games.setup.summary.all_words',
    );
}
