<?php

use App\Domain\Content\Data\RoomCategoryData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSource;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Support\Mechanics\OddOneOutBuilder;
use App\Domain\Rooms\Support\RoomJudge;
use App\Domain\Rooms\Support\RoomPlayable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * «Artıq olanı tap» (docs/architecture.md §12 "Room games"): one of the
 * options does not belong with the others; choose it. Words: all but one
 * from one group of content/rooms/categories.json. Numbers: all but one
 * share a property, and no other property the builder knows singles out
 * another number.
 */

/**
 * @return list<BuiltRoomRoundData>
 */
function oddRounds(RoomLevelData $level, int $seed, int $count = 20): array
{
    return (new OddOneOutBuilder(app(ContentRepository::class)->roomCategories()))->build($level, $count, new Randomizer(new Mt19937($seed)));
}

/**
 * The index of the round's right option, and the options' texts.
 *
 * @return array{int, list<string>}
 */
function oddOptions(BuiltRoomRoundData $round): array
{
    $texts = array_map(static fn (RoomItemData $item): string => (string) $item->text, $round->ask->items);

    return [(int) array_search($round->solution->ids[0], $round->ask->ids(), true), $texts];
}

/**
 * The levels of content/rooms/levels.json of a source, and one of each
 * option count, bound: the content is read once the application has
 * booted.
 *
 * @return array<string, array{Closure(): RoomLevelData}>
 */
function oddLevels(RoomSource $source): array
{
    $levels = [];

    foreach (range(1, 4) as $difficulty) {
        $levels["difficulty {$difficulty}"] = [fn (): RoomLevelData => app(ContentRepository::class)->roomLevel(RoomMechanic::OddOneOut, $difficulty)];
    }

    foreach ([4, 5] as $options) {
        $levels["{$options} options"] = [fn (): RoomLevelData => new RoomLevelData(RoomMechanic::OddOneOut, 1, 0, 15_000, source: $source, options: $options)];
    }

    return $levels;
}

/**
 * Each check of a round, as both tests make it.
 */
function oddCommon(RoomLevelData $level, BuiltRoomRoundData $round): void
{
    [, $texts] = oddOptions($round);

    expect($round->ask->shape)->toBe(RoomAnswerShape::Choice)
        ->and($round->ask->pick)->toBe(1)
        ->and($round->ask->shown)->toBe([])
        ->and($round->study)->toBeNull()
        ->and($round->studyMs)->toBe(0)
        ->and($round->answerMs)->toBe($level->answerMs)
        ->and($texts)->toHaveCount($level->options ?? 0)
        ->and(array_unique(array_map(Az::lower(...), $texts)))->toHaveCount($level->options ?? 0)
        ->and(RoomJudge::correct($round->solution, $round->ask->items, $round->solution->ids))->toBeTrue()
        ->and(array_keys($round->payload()['ask']))->toBe(['shape', 'pick', 'layout', 'grid', 'shown', 'items', 'clue', 'orders'])
        // ADR 037: an anagram's clue and orders, none here.
        ->and($round->ask->clue)->toBeNull()
        ->and($round->ask->orders)->toBe([]);

    foreach ($round->ask->items as $item) {
        expect($item->kind)->toBe($level->source?->kind())
            ->and($item->id)->toMatch('/^[a-z0-9]{8}$/');
    }

    foreach (array_diff($round->ask->ids(), $round->solution->ids) as $wrong) {
        expect(RoomJudge::correct($round->solution, $round->ask->items, [$wrong]))->toBeFalse();
    }
}

it('takes all but one word from one group', function (RoomLevelData $level): void {

    if ($level->source !== RoomSource::Words) {
        expect($level->source)->toBe(RoomSource::Numbers);

        return;
    }

    $groups = [];

    foreach (app(ContentRepository::class)->roomCategories() as $category) {
        foreach ($category->words as $word) {
            $groups[Az::lower($word)] = $category->key;
        }
    }

    $places = [];
    $used = [];

    foreach (range(1, 10) as $seed) {
        foreach (oddRounds($level, $seed) as $round) {
            oddCommon($level, $round);
            [$odd, $texts] = oddOptions($round);
            $of = array_map(static fn (string $text): string => $groups[Az::lower($text)] ?? '', $texts);
            $own = array_values(array_unique(array_values(array_diff_key($of, [$odd => true]))));

            // Every word is a word of the list, as the file writes it; all but one share a group, the odd one is another's.
            expect($of)->not->toContain('')
                ->and($own)->toHaveCount(1)
                ->and($of[$odd])->not->toBe($own[0]);

            $places[$odd] = true;
            $used[$own[0]] = true;
        }
    }

    // The odd one stands in every place; every group comes up.
    expect(array_keys($places))->toEqualCanonicalizing(range(0, ($level->options ?? 0) - 1))
        ->and(array_keys($used))->toEqualCanonicalizing(array_map(static fn (RoomCategoryData $category): string => $category->key, app(ContentRepository::class)->roomCategories()));
})->with(oddLevels(RoomSource::Words));

it('leaves exactly one number without the shared property', function (RoomLevelData $level): void {

    if ($level->source !== RoomSource::Numbers) {
        expect($level->source)->toBe(RoomSource::Words);

        return;
    }

    $properties = OddOneOutBuilder::properties();
    $places = [];
    $shared = [];

    foreach (range(1, 10) as $seed) {
        foreach (oddRounds($level, $seed) as $round) {
            oddCommon($level, $round);
            [$odd, $texts] = oddOptions($round);
            $numbers = array_map(intval(...), $texts);

            foreach ($numbers as $number) {
                expect($number)->toBeGreaterThanOrEqual(OddOneOutBuilder::LEAST_NUMBER)->toBeLessThanOrEqual(OddOneOutBuilder::MOST_NUMBER);
            }

            // A property every other number has (or lacks, for odd numbers) and the odd one does not…
            $by = array_keys(array_filter($properties, static fn (Closure $property): bool => OddOneOutBuilder::singledOut($numbers, $property) === $odd));

            expect($by)->not->toBeEmpty();

            // …and no property the builder knows singles out another number.
            foreach ($properties as $name => $property) {
                expect(OddOneOutBuilder::singledOut($numbers, $property))->toBeIn([null, $odd], "{$name}: ".implode(', ', $numbers));
            }

            $places[$odd] = true;
            $shared += array_flip($by);
        }
    }

    expect(array_keys($places))->toEqualCanonicalizing(range(0, ($level->options ?? 0) - 1))
        ->and(array_keys($shared))->toContain('even', 'square', 'multiple-3', 'multiple-5');
})->with(oddLevels(RoomSource::Numbers));

it('singles out a number as the only one with a property or the only one without it', function (): void {
    $properties = OddOneOutBuilder::properties();

    // 4, 9, 16, 12: the squares leave 12, the parity leaves 9 — a round the builder never makes.
    expect(OddOneOutBuilder::singledOut([4, 9, 16, 12], $properties['square']))->toBe(3)
        ->and(OddOneOutBuilder::singledOut([4, 9, 16, 12], $properties['even']))->toBe(1)
        ->and(OddOneOutBuilder::singledOut([12, 18, 24, 35], $properties['multiple-6']))->toBe(3)
        ->and(OddOneOutBuilder::singledOut([12, 18, 24, 35], $properties['multiple-4']))->toBeNull()
        ->and(OddOneOutBuilder::singledOut([12, 18, 24, 35], $properties['multiple-8']))->toBe(2)
        ->and(OddOneOutBuilder::singledOut([13, 27, 45, 22], $properties['prime']))->toBe(0);
});

it('builds the same rounds from the same seed', function (): void {
    foreach ([2, 3] as $difficulty) {
        $level = app(ContentRepository::class)->roomLevel(RoomMechanic::OddOneOut, $difficulty);
        $first = oddRounds($level, 482_913);

        expect(oddRounds($level, 482_913))->toEqual($first)
            ->and(oddRounds($level, 482_914))->not->toEqual($first);
    }

    expect(RoomPlayable::builder(RoomMechanic::OddOneOut))->toBeInstanceOf(OddOneOutBuilder::class);
});

it('builds only a level of its own', function (RoomLevelData $level, array $categories): void {
    expect(fn () => (new OddOneOutBuilder($categories))->build($level, 1, new Randomizer(new Mt19937(1))))->toThrow(InvalidArgumentException::class);
})->with([
    'no source' => [new RoomLevelData(RoomMechanic::OddOneOut, 1, 0, 15_000, options: 4), []],
    'no options' => [new RoomLevelData(RoomMechanic::OddOneOut, 1, 0, 15_000, source: RoomSource::Numbers), []],
    'something to study' => [new RoomLevelData(RoomMechanic::OddOneOut, 1, 2_000, 15_000, source: RoomSource::Numbers, options: 4), []],
    'one group' => [new RoomLevelData(RoomMechanic::OddOneOut, 1, 0, 15_000, source: RoomSource::Words, options: 4), [new RoomCategoryData('one', ['alma', 'armud', 'heyva', 'nar'])]],
    'a word in two groups' => [new RoomLevelData(RoomMechanic::OddOneOut, 1, 0, 15_000, source: RoomSource::Words, options: 4), [new RoomCategoryData('one', ['alma', 'armud', 'heyva']), new RoomCategoryData('two', ['Alma', 'qatar', 'gəmi'])]],
]);
