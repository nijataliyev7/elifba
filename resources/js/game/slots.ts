/**
 * What a build round's slots hold (docs/architecture.md §2): the text of
 * each slot so far, and the unit whole once every slot is filled. The
 * board's build scenes and the phone's slot row read the same answer, so
 * they cannot differ. Texts are joined, never cased: the server cased
 * every tile already.
 */
import type { Round } from './contract';

type Built = Pick<Round, 'unit' | 'items' | 'slots' | 'solution' | 'prompt'>;

/** One text per slot: the item hit for it so far, null while it is empty. */
export function slotTexts(
    round: Built,
    hitItemIds: readonly string[],
): (string | null)[] {
    const texts = new Map(round.items.map((item) => [item.id, item.text]));

    return Array.from(
        { length: round.slots },
        (_, index) => texts.get(hitItemIds[index] ?? '') ?? null,
    );
}

/**
 * The unit whole, from its solution: letters run together into a
 * syllable and syllables into a word; a sentence's words are spaced and
 * end with its fixed tail. `TUT`, `nənə`, `Tar gözəldir.`
 */
export function wholeText(round: Built): string {
    const texts = new Map(round.items.map((item) => [item.id, item.text]));
    const parts = round.solution.item_ids.map((id) => texts.get(id) ?? '');

    return round.unit === 'sentence'
        ? `${parts.join(' ')}${round.prompt.tail ?? ''}`
        : parts.join('');
}
