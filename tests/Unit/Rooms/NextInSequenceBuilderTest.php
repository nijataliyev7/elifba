<?php

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use App\Domain\Rooms\Support\Mechanics\NextInSequenceBuilder;
use App\Domain\Rooms\Support\Mechanics\SequenceRules;
use App\Domain\Rooms\Support\RoomJudge;
use App\Domain\Rooms\Support\RoomPlayable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * «Sıranı davam etdir» (docs/architecture.md §12 "Room games"): a row of
 * numbers follows one of the level's rules; choose what comes next among
 * four. Nothing is studied: the row is the question's `shown`, the four
 * options its `items`. No rule of the six continues a row otherwise, and
 * nothing in a question tells the right option before the reveal.
 */

/**
 * @return list<BuiltRoomRoundData>
 */
function sequenceRounds(RoomLevelData $level, int $seed, int $count = 20): array
{
    return (new NextInSequenceBuilder)->build($level, $count, new Randomizer(new Mt19937($seed)));
}

/**
 * @param  list<RoomItemData>  $items
 * @return list<int>
 */
function sequenceNumbers(array $items): array
{
    return array_map(static fn (RoomItemData $item): int => (int) $item->text, $items);
}

function sequenceRight(BuiltRoomRoundData $round): int
{
    $right = array_values(array_filter($round->ask->items, static fn (RoomItemData $item): bool => $item->id === $round->solution->ids[0]));

    return (int) $right[0]->text;
}

/**
 * The four levels of content/rooms/levels.json, and a level of each rule
 * alone at its fewest terms, bound: the content is read once the
 * application has booted.
 *
 * @return array<string, array{Closure(): RoomLevelData}>
 */
function sequenceLevels(): array
{
    $levels = [];

    foreach (range(1, 4) as $difficulty) {
        $levels["difficulty {$difficulty}"] = [fn (): RoomLevelData => app(ContentRepository::class)->roomLevel(RoomMechanic::NextInSequence, $difficulty)];
    }

    foreach (RoomSequenceRule::cases() as $rule) {
        $levels["{$rule->value} alone"] = [fn (): RoomLevelData => new RoomLevelData(RoomMechanic::NextInSequence, 4, 0, 30_000, rules: [$rule], terms: $rule->minTerms(), range: new RangeData(1, 999))];
    }

    return $levels;
}

it('continues every rule of the level', function (RoomLevelData $level): void {
    $range = $level->range;
    $followed = [];

    foreach (range(1, 10) as $seed) {
        foreach (sequenceRounds($level, $seed) as $round) {
            $shown = sequenceNumbers($round->ask->shown);
            $right = sequenceRight($round);
            $rules = array_values(array_filter($level->rules, static fn (RoomSequenceRule $rule): bool => SequenceRules::fits($rule, [...$shown, $right])));

            // The row and its next term follow a rule of the level, which continues the row with it.
            expect($rules)->not->toBeEmpty();

            foreach ($rules as $rule) {
                expect(SequenceRules::continuation($rule, $shown))->toBe($right);
                $followed[$rule->value] = true;
            }

            // The level's terms, numbers in its range, nothing studied.
            expect($shown)->toHaveCount($level->terms ?? 0)
                ->and($round->study)->toBeNull()
                ->and($round->studyMs)->toBe(0)
                ->and($round->answerMs)->toBe($level->answerMs);

            foreach ([...$round->ask->shown, ...$round->ask->items] as $item) {
                expect($item->kind)->toBe(RoomItemKind::Number)
                    ->and($item->text)->toMatch('/^[1-9]\d{0,2}$/')
                    ->and((int) $item->text)->toBeGreaterThanOrEqual($range->min ?? 1)->toBeLessThanOrEqual($range->max ?? 999)
                    ->and($item->asset)->toBeNull()
                    ->and($item->cell)->toBeNull();
            }
        }
    }

    // Every rule the level names comes up.
    expect(array_keys($followed))->toEqualCanonicalizing(array_map(static fn (RoomSequenceRule $rule): string => $rule->value, $level->rules));
})->with(sequenceLevels());

it('offers four different numbers with one right', function (RoomLevelData $level): void {
    $places = [];
    $ranks = [];

    foreach (range(1, 10) as $seed) {
        foreach (sequenceRounds($level, $seed) as $round) {
            $ask = $round->ask;
            $options = sequenceNumbers($ask->items);
            $right = sequenceRight($round);
            $shownIds = array_map(static fn (RoomItemData $item): string => $item->id, $ask->shown);

            // A choice of four different numbers, none of them a term of the row.
            expect($ask->shape)->toBe(RoomAnswerShape::Choice)
                ->and($ask->pick)->toBe(1)
                ->and($ask->layout)->toBe(RoomLayout::Row)
                ->and($ask->grid)->toBeNull()
                ->and($options)->toHaveCount(NextInSequenceBuilder::OPTIONS)
                ->and(array_unique($options))->toHaveCount(NextInSequenceBuilder::OPTIONS)
                ->and(array_intersect($options, sequenceNumbers($ask->shown)))->toBe([]);

            // One right: the solution is an option's id, judged right, every other option wrong.
            expect($round->solution->shape)->toBe(RoomAnswerShape::Choice)
                ->and($round->solution->ids)->toHaveCount(1)
                ->and($ask->ids())->toContain($round->solution->ids[0])
                ->and($ask->ids())->not->toContain(...$shownIds)
                ->and(RoomJudge::correct($round->solution, $ask->items, $round->solution->ids))->toBeTrue();

            foreach (array_diff($ask->ids(), $round->solution->ids) as $wrong) {
                expect(RoomJudge::correct($round->solution, $ask->items, [$wrong]))->toBeFalse();
            }

            // Ids: 8 characters, none twice in the round, the row's its own.
            $ids = [...$shownIds, ...$ask->ids()];

            expect(array_unique($ids))->toHaveCount(count($ids));

            foreach ($ids as $id) {
                expect($id)->toMatch('/^[a-z0-9]{8}$/');
            }

            // Nothing a player may see holds the solution.
            // ADR 037: an anagram's clue and orders, none here.
            expect(array_keys($round->payload()['ask']))->toBe(['shape', 'pick', 'layout', 'grid', 'shown', 'items', 'clue', 'orders'])
                ->and($round->ask->clue)->toBeNull()
                ->and($round->ask->orders)->toBe([]);

            $places[array_search($round->solution->ids[0], $ask->ids(), true)] = true;
            $sorted = $options;
            sort($sorted);
            $ranks[array_search($right, $sorted, true)] = true;
        }
    }

    // The right one stands in every place on the page, and at every rank by size: neither tells it.
    expect(array_keys($places))->toEqualCanonicalizing([0, 1, 2, 3])
        ->and(array_keys($ranks))->toEqualCanonicalizing([0, 1, 2, 3]);
})->with(sequenceLevels());

it('builds no row that two rules continue differently', function (RoomLevelData $level): void {
    foreach (range(1, 10) as $seed) {
        foreach (sequenceRounds($level, $seed) as $round) {
            $shown = sequenceNumbers($round->ask->shown);
            $right = sequenceRight($round);

            // All six rules, not only the level's: whichever fits the row continues it with the right option.
            foreach (RoomSequenceRule::cases() as $rule) {
                if (SequenceRules::fits($rule, $shown)) {
                    expect(SequenceRules::continuation($rule, $shown))->toBe($right, "{$rule->value}: ".implode(', ', $shown));
                }
            }

            // So no near miss is what a rule that fits the row would continue it with.
            foreach (sequenceNumbers($round->ask->items) as $option) {
                if ($option !== $right) {
                    foreach (RoomSequenceRule::cases() as $rule) {
                        expect(SequenceRules::fits($rule, $shown) && SequenceRules::continuation($rule, $shown) === $option)->toBeFalse();
                    }
                }
            }
        }
    }
})->with(sequenceLevels());

it('tells a row that two rules continue differently from one they agree on', function (): void {
    // Four squares are also two rows of a step each: 9 + 8 = 17, not 25.
    expect(SequenceRules::fits(RoomSequenceRule::Squares, [1, 4, 9, 16]))->toBeTrue()
        ->and(SequenceRules::fits(RoomSequenceRule::Interleaved, [1, 4, 9, 16]))->toBeTrue()
        ->and(SequenceRules::continuation(RoomSequenceRule::Interleaved, [1, 4, 9, 16]))->toBe(17)
        ->and(NextInSequenceBuilder::told([1, 4, 9, 16], 25))->toBeFalse()
        // A fifth square leaves the squares alone.
        ->and(NextInSequenceBuilder::told([1, 4, 9, 16, 25], 36))->toBeTrue()
        // An arithmetic row is also alternating and interleaved, and they agree.
        ->and(NextInSequenceBuilder::told([4, 7, 10, 13], 16))->toBeTrue()
        // 3, 6, 9 is a step of 3 and a sum of the two before: 12 or 15.
        ->and(NextInSequenceBuilder::told([3, 6, 9], 12))->toBeFalse();

    // Each rule as RoomSequenceRule says it.
    expect(SequenceRules::continuation(RoomSequenceRule::Arithmetic, [4, 7, 10, 13]))->toBe(16)
        ->and(SequenceRules::continuation(RoomSequenceRule::Geometric, [3, 6, 12, 24]))->toBe(48)
        ->and(SequenceRules::continuation(RoomSequenceRule::Geometric, [48, 24, 12, 6]))->toBe(3)
        ->and(SequenceRules::continuation(RoomSequenceRule::Geometric, [4, 6, 9]))->toBeNull()
        ->and(SequenceRules::continuation(RoomSequenceRule::Alternating, [2, 7, 5, 10, 8]))->toBe(13)
        ->and(SequenceRules::continuation(RoomSequenceRule::Squares, [9, 16, 25, 36]))->toBe(49)
        ->and(SequenceRules::continuation(RoomSequenceRule::Squares, [10]))->toBeNull()
        ->and(SequenceRules::continuation(RoomSequenceRule::Fibonacci, [2, 3, 5, 8]))->toBe(13)
        ->and(SequenceRules::continuation(RoomSequenceRule::Interleaved, [1, 20, 3, 17, 5, 14]))->toBe(7)
        ->and(SequenceRules::continuation(RoomSequenceRule::Interleaved, [1, 20, 3]))->toBeNull();
});

it('builds the same rounds from the same seed', function (): void {
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::NextInSequence, 4);
    $first = sequenceRounds($level, 482_913);

    expect(sequenceRounds($level, 482_913))->toEqual($first)
        ->and(sequenceRounds($level, 482_914))->not->toEqual($first)
        ->and(RoomPlayable::builder(RoomMechanic::NextInSequence))->toBeInstanceOf(NextInSequenceBuilder::class);
});

it('builds only a level of its own that leaves a row to build', function (RoomLevelData $level): void {
    expect(fn () => sequenceRounds($level, 1))->toThrow(InvalidArgumentException::class);
})->with([
    'no rules' => [new RoomLevelData(RoomMechanic::NextInSequence, 1, 0, 15_000, terms: 5, range: new RangeData(1, 99))],
    'no range' => [new RoomLevelData(RoomMechanic::NextInSequence, 1, 0, 15_000, rules: [RoomSequenceRule::Arithmetic], terms: 5)],
    'too few terms to tell the rule' => [new RoomLevelData(RoomMechanic::NextInSequence, 1, 0, 15_000, rules: [RoomSequenceRule::Geometric], terms: 4, range: new RangeData(1, 999))],
    'something to study' => [new RoomLevelData(RoomMechanic::NextInSequence, 1, 2_000, 15_000, rules: [RoomSequenceRule::Arithmetic], terms: 4, range: new RangeData(1, 99))],
    'no room for the rule' => [new RoomLevelData(RoomMechanic::NextInSequence, 1, 0, 15_000, rules: [RoomSequenceRule::Geometric], terms: 6, range: new RangeData(10, 99))],
]);
