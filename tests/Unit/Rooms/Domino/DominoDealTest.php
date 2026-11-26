<?php

use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Support\Domino\DominoDeal;
use App\Domain\Rooms\Support\Domino\DominoTile;
use App\Domain\Rooms\Support\RoomSeed;
use Illuminate\Support\Facades\Config;
use Tests\Support\Domino;

/*
 * The tiles and the deal of domino (docs/architecture.md §12 "Domino",
 * ADR 038 decisions 5 and 15, R1, R2, R4): one notation for the 28 tiles;
 * seven tiles a seat and the boneyard in its order, from the game's keyed
 * seed or set by hand; a deal R4 throws away is never dealt.
 */

/**
 * @return list<string>
 */
function dealtNames(DominoDeal $deal): array
{
    return DominoTile::names([...array_merge(...$deal->hands), ...$deal->boneyard]);
}

it('names the 28 tiles one way, the larger number first (ADR 038 R1, decision 15)', function (): void {
    $tiles = DominoTile::all();

    expect($tiles)->toHaveCount(28)
        ->and(array_keys($tiles))->toContain('0-0', '3-0', '6-5', '6-6')
        ->and(DominoTile::of('6-5'))->toBe(DominoTile::pair(5, 6))
        ->and(DominoTile::of('6-5')->pips())->toBe(11)
        ->and(DominoTile::of('4-4')->isDouble())->toBeTrue()
        ->and(DominoTile::of('4-3')->isDouble())->toBeFalse()
        ->and(DominoTile::of('4-3')->other(4))->toBe(3)
        ->and(DominoTile::tryOf('5-6'))->toBeNull()
        ->and(DominoTile::tryOf('7-1'))->toBeNull()
        ->and(DominoTile::tryOf('6-5 '))->toBeNull()
        ->and(DominoTile::tryOf('65'))->toBeNull()
        ->and(DominoTile::isEnd('left'))->toBeTrue()
        ->and(DominoTile::isEnd('middle'))->toBeFalse();

    foreach ($tiles as $name => $tile) {
        expect($tile->name())->toBe($name)->toMatch('/^[0-6]-[0-6]$/')
            ->and($tile->high)->toBeGreaterThanOrEqual($tile->low);
    }
});

it('deals 28 different tiles, seven a seat, and a boneyard of 14, 7 or none (ADR 038 R2)', function (RoomTable $table, int $boneyard): void {
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));

    foreach (range(1, 20) as $seed) {
        $deal = DominoDeal::source($table, $seed)(1, 0);

        expect($deal->hands)->toHaveCount($table->seats())
            ->and($deal->boneyard)->toHaveCount($boneyard)
            ->and($table->boneyard())->toBe($boneyard)
            ->and(dealtNames($deal))->toHaveCount(28)
            ->and(array_unique(dealtNames($deal)))->toHaveCount(28);

        foreach ($deal->hands as $hand) {
            expect($hand)->toHaveCount(7);
        }
    }
})->with([
    'two' => [RoomTable::Two, 14],
    'three' => [RoomTable::Three, 7],
    'four' => [RoomTable::Four, 0],
    'pairs' => [RoomTable::Pairs, 0],
]);

it('gives the same deal from the same seed, hand and try, and another under another key (ADR 038 decision 5)', function (): void {
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
    $deal = static fn (int $seed, int $hand, int $try): array => dealtNames(DominoDeal::shuffled(RoomTable::Two, RoomSeed::deal($seed, $hand, $try)));
    $first = $deal(1234, 1, 0);

    expect($deal(1234, 1, 0))->toBe($first)
        ->and($deal(1235, 1, 0))->not->toBe($first)
        ->and($deal(1234, 2, 0))->not->toBe($first)
        ->and($deal(1234, 1, 1))->not->toBe($first)
        ->and(dealtNames(DominoDeal::source(RoomTable::Two, 1234)(1, 0)))->toBe($first);

    Config::set('app.key', 'base64:'.base64_encode(str_repeat('b', 32)));
    expect($deal(1234, 1, 0))->not->toBe($first);
});

it('throws away a deal with five doubles in a hand (ADR 038 R4)', function (): void {
    $deal = Domino::deal(RoomTable::Two, [
        ['0-0', '1-1', '2-2', '3-3', '4-4', '6-5', '5-1'],
        ['5-5', '6-6', '1-0', '2-0', '3-0', '4-0', '5-0'],
    ]);

    expect($deal->thrown())->toBeTrue();
});

it('throws away a deal with five tiles that pair one number with another (ADR 038 R4, reading)', function (): void {
    $five = Domino::deal(RoomTable::Two, [
        ['1-0', '2-0', '3-0', '4-0', '5-0', '6-6', '5-5'],
        ['6-5', '6-4', '6-3', '5-4', '5-3', '4-3', '2-1'],
    ]);
    // ADR 038 decision 34, reading R4: the double is not counted either
    // way — four of a number with its double stand, six without it do not.
    $fourAndDouble = Domino::deal(RoomTable::Two, [
        ['0-0', '1-0', '2-0', '3-0', '4-0', '6-6', '5-5'],
        ['6-5', '6-4', '6-3', '5-4', '5-3', '4-3', '2-1'],
    ]);
    $sixWithoutDouble = Domino::deal(RoomTable::Two, [
        ['6-0', '1-0', '2-0', '3-0', '4-0', '5-0', '5-5'],
        ['6-5', '6-4', '6-3', '5-4', '5-3', '4-3', '2-1'],
    ]);

    expect($five->thrown())->toBeTrue()
        ->and($fourAndDouble->thrown())->toBeFalse()
        ->and($sixWithoutDouble->thrown())->toBeTrue();
});

it('never deals a deal R4 throws away: a game takes the next try (ADR 038 R4, reading)', function (): void {
    $thrown = Domino::deal(RoomTable::Two, [
        ['0-0', '1-1', '2-2', '3-3', '4-4', '6-5', '5-1'],
        ['5-5', '6-6', '1-0', '2-0', '3-0', '4-0', '5-0'],
    ]);
    $kept = Domino::deal(RoomTable::Two, [
        ['1-1', '6-5', '6-4', '5-4', '6-3', '5-3', '4-3'],
        ['0-0', '2-0', '3-0', '2-2', '3-2', '4-2', '6-6'],
    ]);

    $game = Domino::game(RoomTable::Two, Domino::source([1 => [$thrown, $kept]]), [], Domino::STARTS);

    expect($game->hand()?->deal)->toBe($kept);
});

it('never deals a deal R4 throws away from a seed (ADR 038 R4)', function (RoomTable $table): void {
    Config::set('app.key', 'base64:'.base64_encode(str_repeat('c', 32)));
    $thrown = 0;

    foreach (range(1, 300) as $seed) {
        $source = DominoDeal::source($table, $seed);
        $thrown += $source(1, 0)->thrown() ? 1 : 0;

        expect(Domino::game($table, $source, [], Domino::STARTS)->hand()?->deal->thrown())->toBeFalse();
    }

    // R4 throws a deal away now and then, never most of them.
    expect($thrown)->toBeGreaterThan(0)->toBeLessThan(150);
})->with([RoomTable::Two, RoomTable::Four]);

it('refuses a deal set by hand that is not the 28 tiles, seven a seat', function (array $hands, array $boneyard): void {
    expect(fn () => DominoDeal::set(RoomTable::Four, $hands, $boneyard))->toThrow(InvalidArgumentException::class);
})->with([
    'a hand short' => [[
        ['0-0', '1-0', '1-1', '2-0', '2-1', '2-2'],
        ['3-0', '3-1', '3-2', '3-3', '4-0', '4-1', '4-2'],
        ['4-3', '4-4', '5-0', '5-1', '5-2', '5-3', '5-4'],
        ['5-5', '6-0', '6-1', '6-2', '6-3', '6-4', '6-5'],
    ], ['6-6']],
    'a tile twice' => [[
        ['0-0', '1-0', '1-1', '2-0', '2-1', '2-2', '0-0'],
        ['3-0', '3-1', '3-2', '3-3', '4-0', '4-1', '4-2'],
        ['4-3', '4-4', '5-0', '5-1', '5-2', '5-3', '5-4'],
        ['5-5', '6-0', '6-1', '6-2', '6-3', '6-4', '6-5'],
    ], []],
    'a name written the other way' => [[
        ['0-0', '0-1', '1-1', '2-0', '2-1', '2-2', '6-6'],
        ['3-0', '3-1', '3-2', '3-3', '4-0', '4-1', '4-2'],
        ['4-3', '4-4', '5-0', '5-1', '5-2', '5-3', '5-4'],
        ['5-5', '6-0', '6-1', '6-2', '6-3', '6-4', '6-5'],
    ], []],
    'three hands at four' => [[
        ['0-0', '1-0', '1-1', '2-0', '2-1', '2-2', '6-6'],
        ['3-0', '3-1', '3-2', '3-3', '4-0', '4-1', '4-2'],
        ['4-3', '4-4', '5-0', '5-1', '5-2', '5-3', '5-4'],
    ], ['5-5', '6-0', '6-1', '6-2', '6-3', '6-4', '6-5']],
]);

/*
 * The oracle's deals (`hands.json`, ADR 038 decision 58): deals at every
 * table with R4's verdict and its reasons, a seat at a time.
 */

/**
 * @return array<string, array{0: array<string, mixed>}>
 */
function dominoFixtureDeals(): array
{
    $deals = [];

    foreach (Domino::fixture('hands')['deals'] as $index => $entry) {
        $deals['deal '.($index + 1).": {$entry['table']}, {$entry['verdict']}"] = [$entry];
    }

    return $deals;
}

it('throws away the oracle\'s deal for the same reasons, or keeps it (ADR 038 R4, reading)', function (array $entry): void {
    $deal = Domino::dealOf(RoomTable::from($entry['table']), $entry['deal']);

    expect(['verdict' => $deal->thrown() ? 'thrown' : 'stands', 'why' => Domino::reasonsSet($deal->reasons())])
        ->toBe(['verdict' => $entry['verdict'], 'why' => Domino::reasonsSet($entry['why'])], 'ADR 038 R4: '.json_encode($entry['why']));
})->with(dominoFixtureDeals());
