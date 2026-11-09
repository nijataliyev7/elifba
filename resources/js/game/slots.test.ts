import { describe, expect, it } from 'vite-plus/test';
import { Round } from './contract';
import { slotTexts, wholeText } from './slots';

/*
 * What a build round's slots hold (docs/architecture.md §2), on the
 * contract's own build fixtures: the board's build scenes and the phone's
 * slot row both read it.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/contract/round.build-*.json',
    { eager: true, import: 'default' },
);

function fixture(name: string): Round {
    const found = Object.entries(fixtures).find(([path]) =>
        path.endsWith(`/round.${name}.json`),
    );

    if (found === undefined) {
        throw new Error(`No fixture round.${name}.json`);
    }

    return Round.parse(found[1]);
}

describe('slotTexts', () => {
    it('fills the slots in order and leaves the rest empty', () => {
        const tut = fixture('build-syllable');

        expect(slotTexts(tut, [])).toEqual([null, null, null]);
        expect(slotTexts(tut, ['c'])).toEqual(['T', null, null]);
        expect(slotTexts(tut, ['c', 'a', 'c'])).toEqual(['T', 'U', 'T']);
    });

    it('shows a reused tile in every slot it fills', () => {
        const nene = fixture('build-word-reused-tile');

        expect(slotTexts(nene, ['a'])).toEqual(['nə', null]);
        expect(slotTexts(nene, ['a', 'a'])).toEqual(['nə', 'nə']);
    });

    it('has one entry per slot, whatever the progress says', () => {
        const sentence = fixture('build-sentence');

        expect(slotTexts(sentence, ['a'])).toEqual(['Tar', null]);
        expect(slotTexts(sentence, ['a', 'b', 'c'])).toEqual([
            'Tar',
            'gözəldir',
        ]);
        expect(slotTexts(sentence, ['z'])).toEqual([null, null]);
    });
});

describe('wholeText', () => {
    it('runs letters into a syllable and syllables into a word', () => {
        expect(wholeText(fixture('build-syllable'))).toBe('TUT');
        expect(wholeText(fixture('build-word-reused-tile'))).toBe('nənə');
    });

    it('spaces a sentence’s words and ends it with its tail', () => {
        expect(wholeText(fixture('build-sentence'))).toBe('Tar gözəldir.');
    });
});
