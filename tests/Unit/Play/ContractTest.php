<?php

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Play\Data\BoardEventsData;
use App\Domain\Play\Data\GameStateData;
use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\SyncResultData;
use App\Domain\Play\Enums\SolutionOrder;

/*
 * The round contract (docs/architecture.md §2, ADR 003): every fixture in
 * tests/Fixtures/contract goes through its PHP DTO and comes back exactly
 * — same keys, same order, same values, same types. Vitest parses the same
 * files with the strict zod schemas (resources/js/game/contract.test.ts).
 * The prefix of a file name names its DTO.
 */

const CONTRACT_FIXTURES = __DIR__.'/../../Fixtures/contract';

const CONTRACT_DTOS = [
    'round' => RoundData::class,
    'state' => GameStateData::class,
    'sync-request' => BoardEventsData::class,
    'sync-response' => SyncResultData::class,
    'game-status' => GameStatusData::class,
];

/**
 * @return array<string, array{string}>
 */
function contractFixtures(): array
{
    $files = [];

    foreach (glob(CONTRACT_FIXTURES.'/*.json') ?: [] as $path) {
        if (basename($path) !== 'judge.json') {
            $files[basename($path)] = [$path];
        }
    }

    return $files;
}

/**
 * @return array<string, mixed>
 */
function contractFixture(string $path): array
{
    $decoded = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);

    return is_array($decoded) ? $decoded : throw new RuntimeException("{$path} is not a JSON object.");
}

it('round-trips every fixture exactly', function (string $path): void {
    $prefix = strstr(basename($path), '.', true);
    $dto = CONTRACT_DTOS[$prefix] ?? null;

    expect($dto)->not->toBeNull(basename($path).' has no known prefix ('.implode(', ', array_keys(CONTRACT_DTOS)).').');

    $fixture = contractFixture($path);

    expect($dto::fromArray($fixture)->toArray())->toBe($fixture);
})->with(contractFixtures());

it('has a find round per unit find supports, a round per mechanic and a sequence round with a reused tile', function (): void {
    $rounds = array_map(
        static fn (array $file): RoundData => RoundData::fromArray(contractFixture($file[0])),
        array_filter(contractFixtures(), static fn (string $name): bool => str_starts_with($name, 'round.'), ARRAY_FILTER_USE_KEY),
    );

    $findUnits = array_values(array_unique(array_map(
        static fn (RoundData $round): string => $round->unit->value,
        array_filter($rounds, static fn (RoundData $round): bool => $round->mechanic === Mechanic::Find),
    )));
    sort($findUnits);

    $expected = array_map(static fn (Unit $unit): string => $unit->value, Mechanic::Find->units());
    sort($expected);

    $reusedTile = array_filter($rounds, static fn (RoundData $round): bool => $round->solution->order === SolutionOrder::Sequence
        && count($round->solution->item_ids) !== count(array_unique($round->solution->item_ids)));

    $mechanics = array_values(array_unique(array_map(static fn (RoundData $round): string => $round->mechanic->value, $rounds)));

    expect($findUnits)->toBe($expected)
        ->and($mechanics)->toEqualCanonicalizing(array_map(static fn (Mechanic $mechanic): string => $mechanic->value, Mechanic::cases()))
        ->and($reusedTile)->not->toBeEmpty();
});

it('fails the round trip when a key is added or missing', function (): void {
    $fixture = contractFixture(CONTRACT_FIXTURES.'/round.find-letter.json');

    expect(RoundData::fromArray($fixture)->toArray())->not->toBe([...$fixture, 'extra' => 1]);

    unset($fixture['prompt']['tail']);
    // A missing key is not filled in silently.
    expect(fn () => RoundData::fromArray($fixture))->toThrow(ErrorException::class);
});

it('keeps the payload apart from the id, the index and the total', function (): void {
    $fixture = contractFixture(CONTRACT_FIXTURES.'/round.find-word.json');
    $round = RoundData::fromArray($fixture);

    expect(array_keys($round->payload()))->not->toContain('id', 'index', 'total')
        ->and(RoundData::fromPayload($fixture['id'], $fixture['index'], $fixture['total'], $round->payload())->toArray())->toBe($fixture);
});

it('reads a round stored before prompt.count as count null, and writes the key back', function (): void {
    $fixture = contractFixture(CONTRACT_FIXTURES.'/round.find-word.json');
    $stored = RoundData::fromArray($fixture)->payload();
    unset($stored['prompt']['count']);

    $round = RoundData::fromPayload($fixture['id'], $fixture['index'], $fixture['total'], $stored);

    expect($round->prompt->count)->toBeNull()
        ->and($round->toArray())->toBe($fixture);
});

it('draws the picture of a count round N times and names no number', function (): void {
    $round = RoundData::fromArray(contractFixture(CONTRACT_FIXTURES.'/round.count.json'));
    $answer = $round->item($round->solution->item_ids[0]);

    expect($round->mechanic)->toBe(Mechanic::Count)
        ->and($round->unit)->toBe(Unit::Number)
        ->and($round->prompt->count)->toBe(4)
        ->and($round->prompt->image)->not->toBeNull()
        ->and($round->prompt->show)->toBeNull()
        ->and($answer?->text)->toBe('4')
        ->and($answer?->content_key)->toBe('number:4');
});
