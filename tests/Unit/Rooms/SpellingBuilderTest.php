<?php

use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use App\Domain\Rooms\Support\Mechanics\SpellingBuilder;
use App\Domain\Rooms\Support\RoomJudge;
use App\Domain\Rooms\Support\RoomPlayable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * «Düzgün yazılış» (docs/architecture.md §12 "Room games", ADR 023): one
 * word of the list written four ways, the wrong ones by swapping letters
 * for their confusables. No wrong spelling is a correct one, the four are
 * different, the right one's place is the seed's, and at every letter
 * where they differ two spellings write it one way and two the other.
 */

function spellingWords(): RoomWords
{
    $content = app(ContentRepository::class);

    return RoomWords::of($content->roomWords(), $content->names()->names);
}

/**
 * @return list<BuiltRoomRoundData>
 */
function spellingRounds(RoomLevelData $level, int $seed, int $count = 20, ?RoomWords $words = null): array
{
    return (new SpellingBuilder($words ?? spellingWords()))->build($level, $count, new Randomizer(new Mt19937($seed)));
}

/**
 * @return list<string>
 */
function spellingTexts(BuiltRoomRoundData $round): array
{
    return array_map(static fn (RoomItemData $item): string => (string) $item->text, $round->ask->items);
}

function spellingLevel(int $min, int $max, int $swaps): RoomLevelData
{
    return new RoomLevelData(RoomMechanic::Spelling, 1, 0, 12_000, wordLength: new RangeData($min, $max), swaps: $swaps);
}

/**
 * The four levels of content/rooms/levels.json, bound: the content is read
 * once the application has booted.
 *
 * @return array<string, array{Closure(): RoomLevelData}>
 */
function spellingLevels(): array
{
    $levels = [];

    foreach (range(1, 4) as $difficulty) {
        $levels["difficulty {$difficulty}"] = [fn (): RoomLevelData => app(ContentRepository::class)->roomLevel(RoomMechanic::Spelling, $difficulty)];
    }

    return $levels;
}

it('makes the wrong spellings from confusable letters only', function (RoomLevelData $level): void {
    foreach (range(1, 10) as $seed) {
        foreach (spellingRounds($level, $seed) as $round) {
            $word = (string) $round->solution->word;
            $letters = mb_str_split($word);
            /** @var array<int, true> $changed every place a wrong spelling changes */
            $changed = [];

            foreach (spellingTexts($round) as $spelling) {
                $places = 0;

                foreach (mb_str_split($spelling) as $place => $letter) {
                    if ($letter === $letters[$place]) {
                        continue;
                    }

                    // Only a letter for one of its confusables.
                    expect(Alphabet::confusables($letters[$place]))->toContain($letter);
                    $changed[$place] = true;
                    $places++;
                }

                expect(mb_strlen($spelling))->toBe(mb_strlen($word))
                    ->and($places)->toBeLessThanOrEqual(2);
            }

            // `swaps` 1: two letters, the four ways of writing them; `swaps` 2: three letters, every wrong spelling two of them.
            expect(count($changed))->toBe(($level->swaps ?? 0) + 1);
        }
    }
})->with(spellingLevels());

it('offers no wrong spelling that is a listed word', function (): void {
    $words = spellingWords();

    foreach (range(1, 4) as $difficulty) {
        foreach (range(1, 5) as $seed) {
            foreach (spellingRounds(app(ContentRepository::class)->roomLevel(RoomMechanic::Spelling, $difficulty), $seed) as $round) {
                foreach ($round->ask->items as $item) {
                    if ($item->id !== $round->solution->ids[0]) {
                        expect($words->isCorrect((string) $item->text))->toBeFalse((string) $item->text)
                            ->and((string) $item->text)->not->toStartWith('ğ');
                    }
                }
            }
        }
    }

    // «qələm» and «kələm» are both words: neither is the other's wrong spelling.
    // A form («babam» of «baba») and a first name («Səhər») are correct spellings too.
    $words = new RoomWords(['qələm', 'kələm', 'badam', 'şəhər'], ['babam'], ['Səhər']);

    foreach (range(1, 60) as $seed) {
        foreach (spellingRounds(spellingLevel(5, 5, 1), $seed, 4, $words) as $round) {
            $right = (string) $round->solution->word;
            $wrong = array_values(array_diff(spellingTexts($round), [$right]));

            expect($wrong)->not->toContain('qələm', 'kələm', 'babam', 'səhər')
                ->and($wrong)->toHaveCount(3);
        }
    }

    expect($words->isCorrect('Babam'))->toBeTrue()
        ->and($words->isCorrect('səhər'))->toBeTrue();
});

it('offers four different spellings with one right', function (RoomLevelData $level): void {
    $places = [];

    foreach (range(1, 10) as $seed) {
        foreach (spellingRounds($level, $seed) as $round) {
            $texts = spellingTexts($round);
            $right = $round->solution->ids[0];
            $rightItem = array_values(array_filter($round->ask->items, static fn (RoomItemData $item): bool => $item->id === $right))[0];
            $places[] = array_search($right, $round->ask->ids(), true);

            expect($texts)->toHaveCount(4)
                ->and(array_unique($texts))->toBe($texts)
                ->and($round->solution->shape)->toBe(RoomAnswerShape::Choice)
                ->and($round->solution->ids)->toHaveCount(1)
                ->and($rightItem->text)->toBe($round->solution->word)
                ->and(spellingWords()->words())->toContain($round->solution->word)
                ->and(mb_strlen((string) $round->solution->word))->toBeGreaterThanOrEqual($level->wordLength->min ?? 0)->toBeLessThanOrEqual($level->wordLength->max ?? 0)
                ->and(array_unique(array_map(static fn (RoomItemData $item): RoomItemKind => $item->kind, $round->ask->items), SORT_REGULAR))->toBe([RoomItemKind::Word])
                ->and([$round->ask->shape, $round->ask->pick, $round->ask->layout, $round->ask->grid])->toBe([RoomAnswerShape::Choice, 1, RoomLayout::Row, null])
                // ADR 037: the clue and the orders are an anagram's alone.
                ->and([$round->ask->clue, $round->ask->orders])->toBe([null, []])
                ->and($round->study)->toBeNull()
                ->and($round->studyMs)->toBe(0)
                ->and($round->answerMs)->toBe($level->answerMs)
                ->and(RoomJudge::correct($round->solution, $round->ask->items, [$right]))->toBeTrue();

            foreach ($round->ask->ids() as $id) {
                expect($id)->toMatch('/^[a-z0-9]{8}$/');

                if ($id !== $right) {
                    expect(RoomJudge::correct($round->solution, $round->ask->items, [$id]))->toBeFalse();
                }
            }
        }
    }

    // The right spelling's place is the seed's: each of the four comes up.
    expect(array_values(array_unique($places)))->toEqualCanonicalizing([0, 1, 2, 3]);
})->with(spellingLevels());

it('never makes the right spelling the one the others agree with most', function (RoomLevelData $level): void {
    foreach (range(1, 10) as $seed) {
        foreach (spellingRounds($level, $seed) as $round) {
            $spellings = array_map(mb_str_split(...), spellingTexts($round));

            // At each letter where they differ, two write it one way and two the other.
            foreach (array_keys($spellings[0]) as $place) {
                $written = array_count_values(array_column($spellings, $place));

                expect(count($written) === 1 || array_values($written) === [2, 2])->toBeTrue(implode(' ', spellingTexts($round)));
            }
        }
    }
})->with(spellingLevels());

it('draws only words that make three wrong spellings, none twice in a game', function (): void {
    // «yol» makes one wrong spelling with a letter swapped, «yöl»: never drawn.
    $words = new RoomWords(['yol', 'qaz']);

    expect($words->spellings('yol', 1))->toBe([])
        ->and($words->forSpelling(new RangeData(3, 3), 1))->toBe(['qaz'])
        ->and($words->spellings('qaz', 2))->toBe([]);

    foreach (range(1, 4) as $difficulty) {
        $level = app(ContentRepository::class)->roomLevel(RoomMechanic::Spelling, $difficulty);
        $drawn = array_map(static fn (BuiltRoomRoundData $round): ?string => $round->solution->word, spellingRounds($level, $difficulty, 20));

        expect(array_unique($drawn))->toBe($drawn);
    }
});

it('builds the same rounds from the same seed', function (): void {
    $level = app(ContentRepository::class)->roomLevel(RoomMechanic::Spelling, 3);
    $rounds = static fn (int $seed): array => array_map(
        static fn (BuiltRoomRoundData $round): array => [$round->payload(), $round->solution->toArray()],
        spellingRounds($level, $seed, 10),
    );

    expect($rounds(42))->toBe($rounds(42))
        ->and($rounds(42))->not->toBe($rounds(43))
        ->and(RoomPlayable::builder(RoomMechanic::Spelling))->toBeInstanceOf(SpellingBuilder::class);
});

it('builds only a level of its own', function (RoomLevelData $level): void {
    expect(fn () => spellingRounds($level, 1))->toThrow(InvalidArgumentException::class);
})->with([
    'no word length' => [new RoomLevelData(RoomMechanic::Spelling, 1, 0, 12_000, swaps: 1)],
    'no swaps' => [new RoomLevelData(RoomMechanic::Spelling, 1, 0, 12_000, wordLength: new RangeData(5, 6))],
    'three swaps' => [new RoomLevelData(RoomMechanic::Spelling, 1, 0, 12_000, wordLength: new RangeData(5, 6), swaps: 3)],
    'something to study' => [new RoomLevelData(RoomMechanic::Spelling, 1, 2_000, 12_000, wordLength: new RangeData(5, 6), swaps: 1)],
    'no word of that length' => [new RoomLevelData(RoomMechanic::Spelling, 1, 0, 12_000, wordLength: new RangeData(12, 12), swaps: 1)],
]);
