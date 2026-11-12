import { describe, expect, it } from 'vite-plus/test';
import {
    anyStartable,
    cardLine,
    catalogCards,
    chooseLevel,
    defaultLevel,
    extrasSummary,
    gameExtras,
    isLettersShortage,
    lockedLine,
    mechanicName,
    openGame,
    postedFields,
    readyCard,
    shelves,
    startSummary,
} from '@/lib/setup-choices';
import type { CatalogCard, GameChoice } from '@/lib/setup-choices';
import type {
    Letter,
    RememberedSetup,
    SetupCard,
    SetupGame,
    SetupLevel,
    SetupOption,
    SetupPack,
    SetupRefusal,
} from '@/types';

/*
 * The game catalog's choosing logic (docs/frontend.md rule 13): which
 * cards a mode shows and in what order, «Hazır oyun» for a class and at
 * home, a card's levels and the level it opens with, the remembered
 * choices that still apply, the reason a locked card shows and what a game
 * page posts. The data is shaped like the server's (`GameSetupOptions`),
 * with made-up keys.
 */

const packs: SetupPack[] = ['animals', 'cities', 'train'].map((key) => ({
    key,
    title: key.toUpperCase(),
    cover: `/content/${key}.svg`,
    palette: ['#3B82C4', '#E85D4A', '#F6C445'],
}));

const level = (
    key: string,
    unit: SetupLevel['unit'],
    mechanics: string[],
): SetupLevel => ({ key, title: key, unit, mechanics, hint: `${key} hint` });

const levels: SetupLevel[] = [
    level('letters', 'letter', ['pop', 'find']),
    level('syllables-open', 'syllable', ['pop', 'find', 'build_syllable']),
    level('syllables-closed', 'syllable', ['pop', 'find', 'build_syllable']),
    level('sentences', 'sentence', ['build_sentence']),
    level('pictures', 'picture', ['pop', 'find']),
    level('numbers-5', 'number', ['pop', 'find', 'count']),
];

/** The games in catalog order, as `shared()` sends them. */
const games: SetupGame[] = levels
    .flatMap((item) =>
        item.mechanics.map((mechanic) => ({
            unit: item.unit,
            mechanic,
            mode: mechanic === 'pop' ? ('easy' as const) : ('normal' as const),
            name: `${item.unit} ${mechanic}`,
            hint: `${mechanic} hint`,
        })),
    )
    .filter(
        (game, index, all) =>
            all.findIndex(
                (other) =>
                    other.unit === game.unit &&
                    other.mechanic === game.mechanic,
            ) === index,
    );

/** Cities lists no picture or number unit. */
const lists = (unit: string, pack: string): boolean =>
    pack !== 'cities' || ['letter', 'syllable', 'sentence'].includes(unit);

const refused = (code: string): SetupRefusal => ({
    startable: false,
    reason: `reason ${code}`,
    code,
});

/**
 * Every option of `levels` × `packs` the packs list, startable unless
 * `locked` (by `level|mechanic|pack`, `level|mechanic` or `level`) gives a
 * code; `own` by the same keys.
 */
function options(
    locked: Record<string, string> = {},
    own: Record<string, SetupRefusal> = {},
): SetupOption[] {
    return levels.flatMap((item) =>
        item.mechanics.flatMap((mechanic) =>
            packs
                .filter((pack) => lists(item.unit, pack.key))
                .map((pack): SetupOption => {
                    const keys = [
                        `${item.key}|${mechanic}|${pack.key}`,
                        `${item.key}|${mechanic}`,
                        item.key,
                    ];
                    const code = keys
                        .map((key) => locked[key])
                        .find((value) => value !== undefined);

                    return {
                        level: item.key,
                        mechanic,
                        pack: pack.key,
                        drawable: code !== 'not_playable',
                        own:
                            keys
                                .map((key) => own[key])
                                .find((value) => value !== undefined) ?? null,
                        ...(code === undefined
                            ? { startable: true, reason: null, code: null }
                            : refused(code)),
                    };
                }),
        ),
    );
}

/** One card per game and listing pack, games first, as `for()` sends them. */
const cards: SetupCard[] = games.flatMap((game) =>
    packs
        .filter((pack) => lists(game.unit, pack.key))
        .map((pack) => ({
            pack: pack.key,
            unit: game.unit,
            mechanic: game.mechanic,
            title: `${pack.key} ${game.mechanic}`,
            sample: [],
        })),
);

function catalog(locked: Record<string, string> = {}, own = {}) {
    return catalogCards(
        { packs, levels, games },
        { cards, options: options(locked, own) },
    );
}

function card(all: CatalogCard[], id: string): CatalogCard {
    const found = all.find((item) => item.id === id);

    if (found === undefined) {
        throw new Error(`No card ${id}`);
    }

    return found;
}

const remembered = (
    changes: Partial<RememberedSetup> = {},
): RememberedSetup => ({
    mode: 'normal',
    levels: {},
    letterCases: {},
    letters: null,
    ownWords: null,
    ...changes,
});

const learned: Letter[] = ['a', 'b', 'ə', 'i'].map((lower) => ({
    lower,
    upper: lower === 'i' ? 'İ' : lower.toUpperCase(),
}));

const ids = (all: { cards: CatalogCard[] }[]) =>
    all.map((shelf) => shelf.cards.map((item) => item.id));

describe('catalogCards', () => {
    it('joins each card to its game, its theme and the levels that list its mechanic', () => {
        const all = catalog();
        const syllables = card(all, 'syllable.find.train');

        expect(all).toHaveLength(cards.length);
        expect(syllables.game.name).toBe('syllable find');
        expect(syllables.pack.title).toBe('TRAIN');
        expect(syllables.levels.map((item) => item.level.key)).toEqual([
            'syllables-open',
            'syllables-closed',
        ]);
        expect(card(all, 'number.count.animals').levels).toHaveLength(1);
        expect(syllables.startable).toBe(true);
    });

    it("locks a card only when none of its levels can start, with its first level's reason", () => {
        const all = catalog({
            'syllables-open|find': 'too_few_syllables',
            'syllables-closed|find|train': 'too_few_words',
        });

        expect(card(all, 'syllable.find.animals').startable).toBe(true);
        expect(card(all, 'syllable.find.train')).toMatchObject({
            startable: false,
            reason: 'reason too_few_syllables',
            code: 'too_few_syllables',
        });
    });

    it('drops a card the server sent without an option', () => {
        const all = catalogCards(
            { packs, levels, games },
            {
                cards: [
                    ...cards,
                    {
                        pack: 'cities',
                        unit: 'picture',
                        mechanic: 'find',
                        title: 'x',
                        sample: [],
                    },
                ],
                options: options(),
            },
        );

        expect(all.map((item) => item.id)).not.toContain('picture.find.cities');
    });
});

describe('shelves', () => {
    it('shows only the pop games in «Asan» and every other game in «Normal»', () => {
        const easy = shelves(catalog(), 'easy', false);
        const normal = shelves(catalog(), 'normal', false);

        expect(
            new Set(
                easy.flatMap((shelf) =>
                    shelf.cards.map((item) => item.card.mechanic),
                ),
            ),
        ).toEqual(new Set(['pop']));
        expect(
            normal
                .flatMap((shelf) => shelf.cards)
                .some((item) => item.card.mechanic === 'pop'),
        ).toBe(false);
        // A sentence has no pop: no shelf in «Asan».
        expect(easy.map((shelf) => shelf.unit)).toEqual([
            'letter',
            'syllable',
            'picture',
            'number',
        ]);
        expect(normal.map((shelf) => shelf.unit)).toEqual([
            'letter',
            'syllable',
            'sentence',
            'picture',
            'number',
        ]);
    });

    it('puts the picture and number shelves first at home', () => {
        expect(
            shelves(catalog(), 'normal', true).map((shelf) => shelf.unit),
        ).toEqual(['picture', 'number', 'letter', 'syllable', 'sentence']);
    });

    it('orders a shelf by game, then pack, every card that can start first', () => {
        const all = catalog({
            'syllables-open|build_syllable|animals': 'x',
            'syllables-closed|build_syllable|animals': 'x',
        });
        const syllable = shelves(all, 'normal', false).find(
            (shelf) => shelf.unit === 'syllable',
        );

        expect(syllable?.cards.map((item) => item.id)).toEqual([
            'syllable.find.animals',
            'syllable.find.cities',
            'syllable.find.train',
            'syllable.build_syllable.cities',
            'syllable.build_syllable.train',
            'syllable.build_syllable.animals',
        ]);
    });

    it('drops a shelf with no card in the mode', () => {
        expect(ids(shelves(catalog(), 'easy', false))).not.toContainEqual([]);
    });
});

describe('«Hazır oyun»', () => {
    it('is the first card that can start, in page order: a reading game for a class, a picture game at home', () => {
        expect(readyCard(shelves(catalog(), 'normal', false))?.id).toBe(
            'letter.find.animals',
        );
        expect(readyCard(shelves(catalog(), 'easy', false))?.id).toBe(
            'letter.pop.animals',
        );
        expect(readyCard(shelves(catalog(), 'easy', true))?.id).toBe(
            'picture.pop.animals',
        );
    });

    it('skips what cannot start', () => {
        const all = catalog({ letters: 'too_few_letters' });

        expect(readyCard(shelves(all, 'normal', false))?.id).toBe(
            'syllable.find.animals',
        );
    });

    it('is none, and the catalog says so, when nothing can start at all', () => {
        const everything = Object.fromEntries(
            levels.map((item) => [item.key, 'too_few_letters']),
        );
        const all = catalog(everything);

        expect(readyCard(shelves(all, 'easy', false))).toBeUndefined();
        expect(anyStartable(all)).toBe(false);
        expect(anyStartable(catalog({ letters: 'too_few_letters' }))).toBe(
            true,
        );
    });
});

describe('cardLine and lockedLine', () => {
    it("counts the levels that can start, or gives the one level's hint", () => {
        const all = catalog({
            'syllables-closed|find|train': 'too_few_syllables',
        });

        expect(cardLine(card(all, 'syllable.find.animals'))).toEqual({
            key: 'games.setup.level_count',
            values: { count: 2 },
        });
        expect(cardLine(card(all, 'syllable.find.train'))).toEqual({
            text: 'syllables-open hint',
        });
        expect(cardLine(card(all, 'letter.find.train'))).toEqual({
            text: 'letters hint',
        });
    });

    it('says «Daha çox hərf lazımdır» for a learned-letters shortage, else the reason, or «Tezliklə»', () => {
        const all = catalog({
            letters: 'too_few_letters',
            'sentences|build_sentence|train': 'no_own_words',
            'sentences|build_sentence|cities': 'not_playable',
        });

        expect(cardLine(card(all, 'letter.find.animals'))).toEqual({
            key: 'games.setup.locked.letters',
        });
        expect(cardLine(card(all, 'sentence.build_sentence.train'))).toEqual({
            text: 'reason no_own_words',
        });
        expect(cardLine(card(all, 'sentence.build_sentence.cities'))).toEqual({
            key: 'games.setup.coming_soon',
        });
        expect(lockedLine(refused('too_few_sentences'))).toEqual({
            key: 'games.setup.locked.letters',
        });
        expect(isLettersShortage('too_few_words')).toBe(true);
        expect(isLettersShortage('pack_level')).toBe(false);
        expect(isLettersShortage(null)).toBe(false);
    });
});

describe('a game page', () => {
    it('opens at the remembered level when it can start with this card, else at the first that can', () => {
        const all = catalog({
            'syllables-closed|find|train': 'too_few_syllables',
        });
        const memory = remembered({ levels: { syllable: 'syllables-closed' } });

        expect(
            defaultLevel(card(all, 'syllable.find.animals'), memory)?.level.key,
        ).toBe('syllables-closed');
        expect(
            defaultLevel(card(all, 'syllable.find.train'), memory)?.level.key,
        ).toBe('syllables-open');
        expect(
            defaultLevel(card(all, 'syllable.find.cities'), remembered())?.level
                .key,
        ).toBe('syllables-open');
    });

    it('opens with the remembered case, else capitals for letters and as written otherwise', () => {
        const all = catalog();

        expect(
            openGame(card(all, 'letter.find.train'), remembered(), learned),
        ).toEqual({
            level: 'letters',
            letterCase: 'upper',
            letters: [],
            ownWords: true,
        });
        expect(
            openGame(card(all, 'syllable.find.train'), remembered(), learned)
                ?.letterCase,
        ).toBe('lower');
        expect(
            openGame(
                card(all, 'syllable.find.train'),
                remembered({ letterCases: { syllable: 'upper' } }),
                learned,
            )?.letterCase,
        ).toBe('upper');
    });

    it('keeps remembered letters only at the letter level, while two are still learned', () => {
        const all = catalog();
        const memory = remembered({ letters: ['a', 'ə', 'z'] });

        expect(
            openGame(card(all, 'letter.find.train'), memory, learned)?.letters,
        ).toEqual(['a', 'ə']);
        expect(
            openGame(
                card(all, 'letter.find.train'),
                remembered({ letters: ['a', 'z'] }),
                learned,
            )?.letters,
        ).toEqual([]);
        expect(
            openGame(card(all, 'syllable.find.train'), memory, learned)
                ?.letters,
        ).toEqual([]);
    });

    it('does not open a locked card', () => {
        expect(
            openGame(
                card(
                    catalog({ letters: 'too_few_letters' }),
                    'letter.find.train',
                ),
                remembered(),
                learned,
            ),
        ).toBeNull();
    });

    it('chooses another level that can start, never a locked one', () => {
        const all = catalog({
            'syllables-closed|find|train': 'too_few_syllables',
        });
        const animals = card(all, 'syllable.find.animals');
        const train = card(all, 'syllable.find.train');
        const choice = openGame(animals, remembered(), learned) as GameChoice;

        expect(chooseLevel(animals, choice, 'syllables-closed').level).toBe(
            'syllables-closed',
        );
        expect(chooseLevel(train, choice, 'syllables-closed')).toBe(choice);
        expect(chooseLevel(train, choice, 'missing')).toBe(choice);
    });
});

describe('postedFields', () => {
    const own = { startable: true, reason: null, code: null };

    it("posts the card's game and theme, the level and the letter case where the unit reads", () => {
        const all = catalog();
        const letters = card(all, 'letter.find.cities');
        const choice = openGame(letters, remembered(), learned) as GameChoice;

        expect(postedFields(letters, choice)).toEqual({
            mechanic: 'find',
            pack: 'cities',
            level: 'letters',
            letter_case: 'upper',
        });
        expect(
            postedFields(letters, { ...choice, letters: ['a', 'ə'] }),
        ).toEqual({
            mechanic: 'find',
            pack: 'cities',
            level: 'letters',
            letter_case: 'upper',
            letters: ['a', 'ə'],
        });
    });

    it('posts no letter case, letters or own words where nothing is read', () => {
        const all = catalog();
        const count = card(all, 'number.count.animals');
        const choice: GameChoice = {
            level: 'numbers-5',
            letterCase: 'upper',
            letters: ['a', 'b'],
            ownWords: true,
        };

        expect(postedFields(count, choice)).toEqual({
            mechanic: 'count',
            pack: 'animals',
            level: 'numbers-5',
        });
        expect(
            postedFields(card(all, 'picture.pop.animals'), {
                ...choice,
                level: 'pictures',
            }),
        ).toEqual({
            mechanic: 'pop',
            pack: 'animals',
            level: 'pictures',
        });
    });

    it('posts own words only where they can start at the chosen level, on unless the owner chose otherwise', () => {
        const all = catalog(
            {},
            {
                'syllables-open|find': own,
                'syllables-closed|find': refused('too_few_syllables'),
            },
        );
        const syllables = card(all, 'syllable.find.train');
        const choice = openGame(syllables, remembered(), learned) as GameChoice;

        expect(postedFields(syllables, choice).own_words).toBe('1');
        expect(
            postedFields(syllables, { ...choice, ownWords: false }).own_words,
        ).toBeUndefined();
        expect(
            postedFields(syllables, { ...choice, level: 'syllables-closed' })
                .own_words,
        ).toBeUndefined();
        expect(
            openGame(syllables, remembered({ ownWords: false }), learned)
                ?.ownWords,
        ).toBe(false);
    });

    it('summarises the extras that apply', () => {
        const all = catalog({}, { 'syllables-open|find': own });
        const syllables = card(all, 'syllable.find.train');
        const choice = openGame(syllables, remembered(), learned) as GameChoice;
        const extras = gameExtras(syllables, choice);

        expect(extras).toEqual({
            letterCase: 'lower',
            letters: null,
            ownWords: true,
        });
        expect(startSummary(extras)).toEqual([
            { key: 'games.setup.summary.lower' },
            { key: 'games.setup.summary.own_words' },
        ]);
        expect(
            extrasSummary(
                gameExtras(card(all, 'letter.find.train'), {
                    ...choice,
                    level: 'letters',
                    letterCase: 'upper',
                }),
            ),
        ).toEqual([
            { key: 'games.setup.summary.upper' },
            { key: 'games.setup.summary.all_letters' },
        ]);
        expect(
            gameExtras(card(all, 'number.count.animals'), {
                ...choice,
                level: 'numbers-5',
            }),
        ).toEqual({ letterCase: null, letters: null, ownWords: null });
    });
});

describe('mechanicName', () => {
    it('reads «De və vur» for pop where nothing is read', () => {
        expect(mechanicName('pop', 'picture')).toBe('games.mechanics.pop_say');
        expect(mechanicName('pop', 'letter')).toBe('games.mechanics.pop');
        expect(mechanicName('find', 'number')).toBe('games.mechanics.find');
    });
});
