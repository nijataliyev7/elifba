<?php

namespace App\Domain\Rankings\Support;

/**
 * Dense places (docs/architecture.md §5, ADR 005): the highest score is
 * 1st, equal scores share a place, and the next lower score takes the next
 * place, so a tie never leaves a place empty — 10, 10, 8, 7 → 1, 1, 2, 3.
 * Nothing breaks a tie: not time, not order, not name.
 */
final class DenseRank
{
    /**
     * The place of each score, under the same key.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, int>  $scores
     * @return array<TKey, int>
     */
    public static function places(array $scores): array
    {
        $distinct = array_values(array_unique($scores));
        rsort($distinct);
        $places = array_flip($distinct);

        return array_map(static fn (int $score): int => $places[$score] + 1, $scores);
    }
}
