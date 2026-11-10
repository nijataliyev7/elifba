/**
 * Which items the phone marks as the answer for the teacher
 * (docs/architecture.md §4): the next tile of a `sequence` round, the
 * items still to hit in an `any` round — and none when every item of the
 * round is an answer (`pop`), where a mark on all of them would say
 * nothing.
 */
import type { Round } from './contract';

type Answered = Pick<Round, 'items' | 'solution'>;

export function answerItemIds(
    round: Answered,
    hitItemIds: readonly string[],
): Set<string> {
    const { order, item_ids: solution } = round.solution;

    if (order === 'sequence') {
        const next = solution[hitItemIds.length];

        return new Set(next === undefined ? [] : [next]);
    }

    if (round.items.every((item) => solution.includes(item.id))) {
        return new Set();
    }

    return new Set(solution.filter((id) => !hitItemIds.includes(id)));
}
