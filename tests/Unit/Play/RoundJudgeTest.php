<?php

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Data\SolutionData;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\RoundJudge;

/*
 * The judge cases shared with Vitest (resources/js/game/engine.test.ts):
 * each case plays its hits in order from its starting progress and checks
 * every verdict and the running miss count.
 */

/**
 * @return array<string, array{array{name: string, items: list<string>, solution: array{order: string, item_ids: list<string>}, progress?: list<string>, hits: list<array{item_id: string, status: string, progress: list<string>, misses: int, solved: bool}>}}>
 */
function judgeCases(): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/contract/judge.json'), true, 64, JSON_THROW_ON_ERROR);
    $cases = [];

    foreach ($decoded['cases'] as $case) {
        $cases[$case['name']] = [$case];
    }

    return $cases;
}

/**
 * @param  list<string>  $items
 * @param  array{order: string, item_ids: list<string>}  $solution
 */
function judgedRound(array $items, array $solution): RoundData
{
    $order = SolutionOrder::from($solution['order']);

    return new RoundData(
        id: '01JC8Z6V8Q4B2X5N7M9P3R6T0A',
        index: 0,
        total: 1,
        mechanic: $order === SolutionOrder::Any ? Mechanic::Find : Mechanic::BuildWord,
        unit: $order === SolutionOrder::Any ? Unit::Letter : Unit::Word,
        pack: 'test',
        level: 'test',
        prompt: new PromptData('test', null, null, null, null),
        items: array_map(static fn (string $id): RoundItemData => new RoundItemData($id, $id, null, "test:{$id}"), $items),
        slots: $order === SolutionOrder::Any ? 0 : count($solution['item_ids']),
        solution: new SolutionData($order, $solution['item_ids']),
        effect: Effect::Burst,
    );
}

it('judges like the shared cases say', function (array $case): void {
    $round = judgedRound($case['items'], $case['solution']);
    $progress = $case['progress'] ?? [];
    $misses = 0;

    foreach ($case['hits'] as $hit) {
        $verdict = RoundJudge::judge($round, $progress, $hit['item_id']);

        if ($verdict->status->value === 'miss') {
            $misses++;
        }

        $progress = $verdict->progress;

        expect([
            'item_id' => $hit['item_id'],
            'status' => $verdict->status->value,
            'progress' => $verdict->progress,
            'misses' => $misses,
            'solved' => $verdict->solved,
        ])->toBe($hit);
    }
})->with(judgeCases());

it('covers the cases the contract names', function (): void {
    $names = implode("\n", array_keys(judgeCases()));

    expect($names)->toContain('reused tile (nə-nə)')
        ->toContain('a miss')
        ->toContain('two misses on the same decoy')
        ->toContain('after the round is solved');
});
