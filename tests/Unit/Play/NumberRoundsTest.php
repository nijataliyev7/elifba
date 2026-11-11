<?php

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Support\Mechanics\FindRoundBuilder;
use App\Domain\Play\Support\Mechanics\PopRoundBuilder;
use App\Domain\Play\Support\Mechanics\RoundParts;
use App\Domain\Play\Support\Playable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * `find` and `pop` at a number level (docs/architecture.md §2 "Picture and
 * number rounds", ADR 012): an item is a number of the level's range, shown
 * as its digits, with no picture; the decoys are the nearest numbers first.
 * Nothing of the pack is read, so the learned letters play no part. The
 * board's side — a number stands alone, with no animal behind it — is
 * `figures.test.ts`.
 */

/**
 * @param  list<string>|null  $learned
 * @return list<RoundData>
 */
function numberRounds(string $level = 'numbers-10', Mechanic $mechanic = Mechanic::Find, string $pack = 'fruit', int $rounds = 30, int $seed = 5, ?array $learned = null): array
{
    $content = app(ContentRepository::class);
    $setup = new GameSetupData($mechanic, $pack, $level, $rounds, LetterCase::Lower);
    $builder = $mechanic === Mechanic::Pop ? app(PopRoundBuilder::class) : app(FindRoundBuilder::class);

    return $builder->build($setup, $content->pack($pack), $learned ?? Alphabet::letters(), new Randomizer(new Mt19937($seed)));
}

/**
 * @return list<int>
 */
function numbersOf(RoundData $round): array
{
    return array_map(static fn (RoundItemData $item): int => (int) $item->text, $round->items);
}

it('shows each number as its digits, with no picture, and names it in the prompt', function (string $level, int $items): void {
    foreach (numberRounds($level) as $round) {
        $target = $round->item($round->solution->item_ids[0]);

        expect($round->unit)->toBe(Unit::Number)
            ->and($round->items)->toHaveCount($items)
            ->and($round->prompt->text)->toBe("«{$target?->text}» rəqəmini tap!")
            ->and($round->prompt->show)->toBe($target?->text)
            ->and($round->prompt->image)->toBeNull()
            ->and($round->prompt->count)->toBeNull();

        foreach ($round->items as $item) {
            expect($item->text)->toMatch('/^(?:[1-9]|10)$/')
                ->and($item->image)->toBeNull()
                ->and($item->content_key)->toBe("number:{$item->text}");
        }
    }
})->with([
    'numbers-5' => ['numbers-5', 3],
    'numbers-10' => ['numbers-10', 4],
]);

it('takes the nearest numbers as decoys', function (): void {
    foreach (numberRounds('numbers-10', rounds: 40) as $round) {
        $target = (int) $round->item($round->solution->item_ids[0])?->text;
        $distances = array_map(static fn (int $number): int => abs($number - $target), numbersOf($round));
        sort($distances);

        // The target, then the nearest: ±1 on both sides wherever the range allows.
        $nearest = array_map(
            static fn (int $number): int => abs($number - $target),
            array_slice(array_values(array_filter(
                (static function () use ($target): array {
                    $all = range(1, 10);
                    usort($all, static fn (int $a, int $b): int => abs($a - $target) <=> abs($b - $target));

                    return $all;
                })(),
            )), 0, 4),
        );

        expect($distances)->toBe($nearest, "target {$target}");
    }

    $random = new Randomizer(new Mt19937(1));
    $pool = array_map(static fn (int $n): string => "number:{$n}", range(1, 10));

    expect(RoundParts::nearest('number:1', $pool, 3, $random))->toBe(['number:2', 'number:3', 'number:4'])
        ->and(RoundParts::nearest('number:10', $pool, 2, $random))->toBe(['number:9', 'number:8'])
        ->and(RoundParts::nearest('number:5', $pool, 2, $random))->toEqualCanonicalizing(['number:4', 'number:6']);
});

it('stays inside the level\'s range', function (): void {
    $five = array_merge(...array_map(numbersOf(...), numberRounds('numbers-5', rounds: 30)));
    $ten = array_merge(...array_map(numbersOf(...), numberRounds('numbers-10', rounds: 30)));

    expect(min($five))->toBe(1)
        ->and(max($five))->toBe(5)
        ->and(min($ten))->toBe(1)
        ->and(max($ten))->toBe(10);
});

it('needs nothing from the pack and ignores the learned letters', function (): void {
    $content = app(ContentRepository::class);
    $empty = TeacherPacks::merge($content->pack('animals'), [], array_map(static fn ($word): string => $word->key(), $content->pack('animals')->words));
    $shape = static fn (array $rounds): array => array_map(static fn (RoundData $round): array => [$round->prompt->text, numbersOf($round)], $rounds);

    expect(Playable::reason(Mechanic::Find, $empty, $content->level('numbers-5'), ['a']))->toBeNull()
        ->and(Playable::reason(Mechanic::Pop, $empty, $content->level('numbers-10'), ['a']))->toBeNull()
        ->and($shape(numberRounds(learned: ['a'])))->toBe($shape(numberRounds()));
});

it('hits every number in pop, with the template that names none', function (): void {
    foreach (numberRounds('numbers-5', Mechanic::Pop, rounds: 10) as $round) {
        expect($round->solution->item_ids)->toBe($round->itemIds())
            ->and($round->prompt->text)->toBe('Hər rəqəmi de və vur!')
            ->and($round->prompt->show)->toBeNull();
    }
});
