import { describe, expect, it } from 'vite-plus/test';
import { answerItemIds } from './answers';
import { Round } from './contract';

/*
 * Which items the phone marks as the answer (docs/architecture.md §4), on
 * the contract's own round fixtures.
 */
const fixtures = import.meta.glob<unknown>(
    '../../../tests/Fixtures/contract/round.*.json',
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

describe('answerItemIds', () => {
    it('marks the target of a find round until it is hit', () => {
        const round = fixture('find-letter');

        expect([...answerItemIds(round, [])]).toEqual(['b']);
        expect([...answerItemIds(round, ['b'])]).toEqual([]);
    });

    it('marks the next tile of a build round, a reused one again', () => {
        const tut = fixture('build-syllable');

        expect([...answerItemIds(tut, [])]).toEqual(['c']);
        expect([...answerItemIds(tut, ['c'])]).toEqual(['a']);
        expect([...answerItemIds(tut, ['c', 'a'])]).toEqual(['c']);
        expect([...answerItemIds(tut, ['c', 'a', 'c'])]).toEqual([]);
    });

    it('marks the picture of a picture round and the number of a count round (R5)', () => {
        const picture = fixture('find-picture');
        const count = fixture('count');
        const answer = count.items.find((item) =>
            answerItemIds(count, []).has(item.id),
        );

        expect([...answerItemIds(picture, [])]).toEqual(['b']);
        expect(picture.items.find((item) => item.id === 'b')?.text).toBe(
            'pişik',
        );
        expect([...answerItemIds(picture, ['b'])]).toEqual([]);
        expect(answer?.text).toBe(String(count.prompt.count));
        // A wrong number stays: the answer is still marked after a miss.
        expect([...answerItemIds(count, [])]).toEqual(['c']);
        expect([...answerItemIds(count, ['c'])]).toEqual([]);
    });

    it('marks nothing in a pop round, where every item is an answer', () => {
        const round = fixture('pop-word');

        expect(round.solution.item_ids).toHaveLength(round.items.length);
        expect([...answerItemIds(round, [])]).toEqual([]);
        expect([...answerItemIds(round, ['b', 'd'])]).toEqual([]);
    });
});
