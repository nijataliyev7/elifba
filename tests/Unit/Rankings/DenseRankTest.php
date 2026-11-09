<?php

use App\Domain\Rankings\Support\DenseRank;

/*
 * Dense places (docs/architecture.md §5, ADR 005): ties share a place and
 * the next score takes the next place, so no place is left empty.
 */

it('places scores densely, the highest first', function (array $scores, array $places): void {
    expect(DenseRank::places($scores))->toBe($places);
})->with([
    'an empty list' => [[], []],
    'a single student' => [[4], [1]],
    'everyone equal' => [[5, 5, 5], [1, 1, 1]],
    'a tie for first' => [[10, 10, 8, 7], [1, 1, 2, 3]],
    'a tie further down' => [[9, 6, 6, 2], [1, 2, 2, 3]],
    'scores in any order' => [[7, 10, 8, 10], [3, 1, 2, 1]],
]);

it('keeps the keys of the scores', function (): void {
    expect(DenseRank::places(['aysel' => 3, 'əli' => 6, 'leyla' => 3]))
        ->toBe(['aysel' => 2, 'əli' => 1, 'leyla' => 2]);
});
