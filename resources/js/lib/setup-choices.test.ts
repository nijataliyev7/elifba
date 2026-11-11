import { describe, expect, it } from 'vite-plus/test';
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
    mechanicName,
    packChoices,
    roundSteps,
    setupCatalog,
    startSummary,
    unitGroups,
} from '@/lib/setup-choices';
import type { SetupLevel, SetupOption, SetupPack } from '@/types';

/*
 * The game setup's choosing logic (docs/frontend.md rule 13): the order of
 * the units, the defaults, which later choices survive a change and the
 * reason a locked choice shows. The data is shaped like the server's
 * (`GameSetupOptions`), with made-up keys.
 */

const packs: SetupPack[] = ['animals', 'cities', 'train'].map((key) => ({
    key,
    title: key.toUpperCase(),
    cover: `/content/${key}.svg`,
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

const refused = (code: string) => ({
    startable: false,
    reason: `reason ${code}`,
    code,
});

/** Every option of `levels` × `packs` (cities lists no picture or number unit), startable unless `locked` says otherwise. */
function options(
    locked: Record<string, string> = {},
    skip: (level: string, pack: string) => boolean = (key, pack) =>
        pack === 'cities' && ['pictures', 'numbers-5'].includes(key),
): SetupOption[] {
    return levels.flatMap((item) =>
        item.mechanics.flatMap((mechanic) =>
            packs
                .filter((pack) => !skip(item.key, pack.key))
                .map((pack): SetupOption => {
                    const code =
                        locked[`${item.key}|${mechanic}|${pack.key}`] ??
                        locked[`${item.key}|${mechanic}`] ??
                        locked[item.key];

                    return {
                        level: item.key,
                        mechanic,
                        pack: pack.key,
                        drawable: code !== 'not_playable',
                        own: null,
                        ...(code === undefined
                            ? { startable: true, reason: null, code: null }
                            : refused(code)),
                    };
                }),
        ),
    );
}

const catalog = (
    home = false,
    locked: Record<string, string> = {},
    skip?: (level: string, pack: string) => boolean,
) => setupCatalog(packs, levels, options(locked, skip), home);

function started(home = false, locked: Record<string, string> = {}) {
    const items = catalog(home, locked);
    const state = initialState(items);

    if (state === null) {
        throw new Error('nothing can start');
    }

    return { items, state };
}

describe('unitGroups', () => {
    it('lists «Oxu» first for a class and «Oxumadan» first at home, units in the content order', () => {
        const summary = (home: boolean) =>
            unitGroups(catalog(home)).map((group) => [
                group.key,
                group.units.map((unit) => unit.unit),
            ]);

        expect(summary(false)).toEqual([
            ['reading', ['letter', 'syllable', 'sentence']],
            ['pictures', ['picture', 'number']],
        ]);
        expect(summary(true)).toEqual([
            ['pictures', ['picture', 'number']],
            ['reading', ['letter', 'syllable', 'sentence']],
        ]);
    });

    it('hides a unit with no option at all', () => {
        const groups = unitGroups(
            catalog(false, {}, (key) => key === 'sentences'),
        );

        expect(groups[0].units.map((unit) => unit.unit)).toEqual([
            'letter',
            'syllable',
        ]);
    });

    it("gives a unit that cannot start its first level's, first mechanic's, first theme's reason", () => {
        const items = catalog(false, {
            'syllables-open|pop|animals': 'too_few_syllables',
            'syllables-open': 'not_playable',
            'syllables-closed': 'too_few_words',
        });
        const syllable = unitGroups(items)[0].units[1];

        expect(syllable).toMatchObject({
            unit: 'syllable',
            startable: false,
            reason: 'reason too_few_syllables',
            code: 'too_few_syllables',
        });
        expect(isLettersShortage(syllable.code)).toBe(true);
        expect(isLettersShortage('not_playable')).toBe(false);
        expect(isLettersShortage(null)).toBe(false);
    });

    it('keeps a unit open when one of its levels can start', () => {
        const items = catalog(false, {
            'syllables-closed': 'too_few_syllables',
        });

        expect(unitGroups(items)[0].units[1].startable).toBe(true);
        expect(
            levelChoices(items, 'syllable').map((choice) => [
                choice.level.key,
                choice.startable,
                choice.code,
            ]),
        ).toEqual([
            ['syllables-open', true, null],
            ['syllables-closed', false, 'too_few_syllables'],
        ]);
    });
});

describe('mechanicChoices and packChoices', () => {
    it("list a level's mechanics in the level's order and its themes in the content's order", () => {
        const items = catalog();

        expect(
            mechanicChoices(items, 'numbers-5').map((item) => item.mechanic),
        ).toEqual(['pop', 'find', 'count']);
        expect(
            packChoices(items, 'pictures', 'find').map((item) => item.pack.key),
        ).toEqual(['animals', 'train']);
        expect(
            packChoices(items, 'letters', 'find').map((item) => item.pack.key),
        ).toEqual(['animals', 'cities', 'train']);
    });

    it("carry the server's reason when locked", () => {
        const items = catalog(false, {
            'letters|find|animals': 'not_playable',
            'letters|find|cities': 'too_few_letters',
            'letters|find|train': 'too_few_letters',
        });

        expect(mechanicChoices(items, 'letters')[1]).toEqual({
            mechanic: 'find',
            startable: false,
            reason: 'reason not_playable',
            code: 'not_playable',
        });
        expect(packChoices(items, 'letters', 'find')[0]).toMatchObject({
            drawable: false,
            startable: false,
            reason: 'reason not_playable',
        });
    });
});

describe('initialState', () => {
    it('opens a class on the first reading game that can start', () => {
        expect(started().state.choice).toEqual({
            unit: 'letter',
            level: 'letters',
            mechanic: 'pop',
            pack: 'animals',
        });
    });

    it('opens a home game on the first picture game', () => {
        expect(started(true).state.choice).toEqual({
            unit: 'picture',
            level: 'pictures',
            mechanic: 'pop',
            pack: 'animals',
        });
    });

    it('never opens on a locked choice', () => {
        const { state } = started(false, {
            letters: 'too_few_letters',
            'syllables-open': 'too_few_syllables',
            'syllables-closed|pop': 'too_few_syllables',
            'syllables-closed|find|animals': 'too_few_syllables',
        });

        expect(state.choice).toEqual({
            unit: 'syllable',
            level: 'syllables-closed',
            mechanic: 'find',
            pack: 'cities',
        });
        expect(state.picked).toEqual({ mechanic: false, pack: false });
    });

    it('is null when nothing can start', () => {
        const locked = Object.fromEntries(
            levels.map((item) => [item.key, 'too_few_letters']),
        );

        expect(initialState(catalog(false, locked))).toBeNull();
    });
});

describe('a change filters only the later questions', () => {
    it('keeps the earlier choices when a later one changes', () => {
        const { items, state } = started();
        const level = chooseLevel(
            items,
            chooseUnit(items, state, 'syllable'),
            'syllables-closed',
        );
        const mechanic = chooseMechanic(items, level, 'build_syllable');
        const pack = choosePack(items, mechanic, 'train');

        expect(mechanic.choice.level).toBe('syllables-closed');
        expect(mechanic.choice.unit).toBe('syllable');
        expect(pack.choice).toEqual({
            unit: 'syllable',
            level: 'syllables-closed',
            mechanic: 'build_syllable',
            pack: 'train',
        });
    });

    it('keeps the mechanic and the theme across levels that play them', () => {
        const { items, state } = started();
        const picked = choosePack(
            items,
            chooseMechanic(items, state, 'find'),
            'cities',
        );
        const next = chooseUnit(items, picked, 'syllable');

        expect(next.choice).toEqual({
            unit: 'syllable',
            level: 'syllables-open',
            mechanic: 'find',
            pack: 'cities',
        });
        expect(next.replaced).toEqual({ mechanic: null, pack: null });
        expect(next.picked).toEqual({ mechanic: true, pack: true });
    });

    it("replaces the adult's picks that cannot start with the first that can, and says so", () => {
        const { items, state } = started();
        const picked = choosePack(
            items,
            chooseMechanic(items, chooseUnit(items, state, 'number'), 'count'),
            'train',
        );
        const pictures = chooseUnit(
            items,
            choosePack(items, picked, 'animals'),
            'picture',
        );

        expect(pictures.choice).toMatchObject({
            level: 'pictures',
            mechanic: 'pop',
            pack: 'animals',
        });
        expect(pictures.replaced).toEqual({
            mechanic: { from: 'count', to: 'pop' },
            pack: null,
        });

        const cities = chooseUnit(
            items,
            choosePack(items, chooseUnit(items, state, 'syllable'), 'cities'),
            'picture',
        );

        expect(cities.choice.pack).toBe('animals');
        expect(cities.replaced.pack).toEqual({ from: 'cities', to: 'animals' });
    });

    it('says nothing when it replaces a default, and clears the line at the next choice', () => {
        const { items, state } = started(false, {
            'syllables-open|pop': 'too_few_syllables',
        });
        const syllables = chooseUnit(items, state, 'syllable');

        expect(syllables.choice.mechanic).toBe('find');
        expect(syllables.replaced).toEqual({ mechanic: null, pack: null });

        const replaced = chooseUnit(
            items,
            chooseMechanic(items, chooseUnit(items, state, 'number'), 'count'),
            'letter',
        );

        expect(replaced.replaced.mechanic).not.toBeNull();
        expect(choosePack(items, replaced, 'cities').replaced).toEqual({
            mechanic: null,
            pack: null,
        });
    });

    it('forgets a replaced choice: it never comes back by itself', () => {
        const { items, state } = started();
        const count = chooseMechanic(
            items,
            chooseUnit(items, state, 'number'),
            'count',
        );
        const back = chooseUnit(
            items,
            chooseUnit(items, count, 'picture'),
            'number',
        );

        expect(back.choice.mechanic).toBe('pop');
        expect(back.picked.mechanic).toBe(false);
    });

    it('never chooses a locked unit, level, mechanic or theme', () => {
        const { items, state } = started(false, {
            sentences: 'too_few_sentences',
            'syllables-closed': 'too_few_syllables',
            'letters|find': 'too_few_letters',
            'letters|pop|cities': 'not_playable',
        });

        expect(chooseUnit(items, state, 'sentence')).toBe(state);
        expect(chooseMechanic(items, state, 'find')).toBe(state);
        expect(choosePack(items, state, 'cities')).toBe(state);

        const syllables = chooseUnit(items, state, 'syllable');

        expect(chooseLevel(items, syllables, 'syllables-closed')).toBe(
            syllables,
        );
        expect(chooseUnit(items, syllables, 'syllable')).toBe(syllables);
    });
});

describe('names and summaries', () => {
    it('names pop «De və vur» where nothing is read', () => {
        expect(mechanicName('pop', 'picture')).toBe('games.mechanics.pop_say');
        expect(mechanicName('pop', 'number')).toBe('games.mechanics.pop_say');
        expect(mechanicName('pop', 'letter')).toBe('games.mechanics.pop');
        expect(mechanicName('count', 'number')).toBe('games.mechanics.count');
    });

    it('offers multiples of 5, and a configured default that is none of them', () => {
        const rounds = { min: 5, max: 30 };

        expect(roundSteps(rounds, 10)).toEqual([5, 10, 15, 20, 25, 30]);
        expect(roundSteps(rounds, 7)).toEqual([5, 7, 10, 15, 20, 25, 30]);
        expect(roundSteps(rounds, 40)).toEqual([5, 10, 15, 20, 25, 30]);
    });

    it('names every extra that applies beside «Əlavə», and only the chosen ones in the start bar', () => {
        const letters = {
            rounds: 10,
            letterCase: 'upper' as const,
            letters: 0,
            ownWords: null,
        };

        expect(extrasSummary(letters)).toEqual([
            { key: 'games.board.rounds', values: { count: 10 } },
            { key: 'games.setup.summary.upper' },
            { key: 'games.setup.summary.all_letters' },
        ]);
        expect(startSummary(letters)).toEqual([
            { key: 'games.board.rounds', values: { count: 10 } },
            { key: 'games.setup.summary.upper' },
        ]);
        expect(startSummary({ ...letters, letters: 3 })).toContainEqual({
            key: 'games.setup.summary.letters',
            values: { count: 3 },
        });

        const words = {
            rounds: 15,
            letterCase: 'lower' as const,
            letters: null,
            ownWords: true,
        };

        expect(startSummary(words).map((item) => item.key)).toEqual([
            'games.board.rounds',
            'games.setup.summary.lower',
            'games.setup.summary.own_words',
        ]);
        expect(
            extrasSummary({ ...words, ownWords: false }).map(
                (item) => item.key,
            ),
        ).toContain('games.setup.summary.all_words');
        expect(
            startSummary({
                rounds: 5,
                letterCase: null,
                letters: null,
                ownWords: null,
            }),
        ).toEqual([{ key: 'games.board.rounds', values: { count: 5 } }]);
    });
});
