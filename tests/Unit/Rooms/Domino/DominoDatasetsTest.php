<?php

/*
 * The oracle's fixtures in the domino tests' datasets: Pest keeps every
 * dataset for the whole run, so a dataset of the fixtures names their
 * entries — an index, with the entry's name as the case's — and each test
 * loads its own entry (`Domino::fixture()`), as `DominoEngineTest` walks
 * its positions again for each test: the run never holds a fixture whole.
 */

it('names the oracle\'s entries in a dataset and holds none of them', function (string $dataset, array $arguments): void {
    if (! function_exists($dataset)) {
        $this->markTestSkipped("{$dataset}() is a test file's that this run did not collect: run tests/Unit/Rooms/Domino.");
    }

    $rows = $dataset(...$arguments);

    expect($rows)->not->toBeEmpty();

    foreach ($rows as $name => $row) {
        expect(array_filter($row, static fn (mixed $value): bool => ! is_int($value) && ! is_string($value)))->toBe([], "{$dataset}() · {$name}: a name or an index, never the entry");
    }
})->with([
    'the deals of hands.json' => ['dominoFixtureDeals', []],
    'the hands of hands.json' => ['dominoFixtureHands', []],
    'the games of games.json' => ['dominoFixtureGames', []],
    'the cases of cases.json' => ['dominoFixtureCases', [array_map(static fn (int $rule): string => "R{$rule}", range(1, 26))]],
    'the sequences of ledger.json' => ['dominoFixtureSequences', []],
    'the results of study.json' => ['dominoFixtureStudyResults', []],
    'the hands of study.json' => ['dominoFixtureStudyHands', []],
]);
