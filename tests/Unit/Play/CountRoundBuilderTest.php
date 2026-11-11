<?php

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\CountRoundBuilder;
use App\Domain\Play\Support\Playable;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\Voice;

/*
 * `count` rounds («Neçə alma var?»; docs/architecture.md §2 "The count
 * builder", ADR 012): the prompt shows one picture of the pack N times, the
 * items are numbers, and the one that says N solves the round. Two decks:
 * what is counted (the pack's words with a picture) and how many (the
 * level's range).
 */

// Which clips are made is content: no clip here, so prompt.audio is null;
// tests/Feature/Play/PromptVoiceTest checks it with clips.
beforeEach(fn () => Voice::none($this->app));

/**
 * @return list<RoundData>
 */
function countRounds(string $level = 'numbers-5', string $pack = 'fruit', int $rounds = 10, int $seed = 3, ?Closure $packAs = null): array
{
    $content = app(ContentRepository::class);
    $setup = new GameSetupData(Mechanic::Count, $pack, $level, $rounds, LetterCase::Lower);
    $packData = $packAs instanceof Closure ? $packAs($content->pack($pack)) : $content->pack($pack);

    return app(CountRoundBuilder::class)->build($setup, $packData, Alphabet::letters(), new Randomizer(new Mt19937($seed)));
}

it('shows the picture N times and never the number', function (string $pack, string $level): void {
    $packData = app(ContentRepository::class)->pack($pack);
    $items = app(ContentRepository::class)->level($level)->items;

    foreach (countRounds($level, $pack, rounds: 20) as $index => $round) {
        $word = null;

        foreach ($packData->words as $candidate) {
            if ($round->prompt->text === "Neçə «{$candidate->text}» var?") {
                $word = $candidate;
            }
        }

        expect($round->index)->toBe($index)
            ->and($round->mechanic)->toBe(Mechanic::Count)
            ->and($round->unit)->toBe(Unit::Number)
            ->and($word)->not->toBeNull($round->prompt->text)
            ->and($round->prompt->image)->toBe(ContentAssets::url((string) $word?->image, $packData->version))
            ->and($round->prompt->count)->toBeGreaterThanOrEqual(1)
            ->and($round->prompt->show)->toBeNull()
            ->and($round->prompt->text)->not->toContain((string) $round->prompt->count)
            ->and($round->prompt->audio)->toBeNull()
            ->and($round->prompt->tail)->toBeNull()
            ->and($round->items)->toHaveCount($items)
            ->and($round->itemIds())->toBe(array_slice(['a', 'b', 'c', 'd'], 0, $items))
            ->and($round->slots)->toBe(0)
            ->and($round->effect)->toBe($packData->style->effect);

        foreach ($round->items as $item) {
            expect($item->image)->toBeNull()
                ->and($item->content_key)->toBe("number:{$item->text}");
        }
    }
})->with([
    'fruit, 1–5' => ['fruit', 'numbers-5'],
    'animals, 1–10' => ['animals', 'numbers-10'],
]);

it('solves with the one item that says N, among its nearest numbers', function (): void {
    foreach (countRounds('numbers-10', rounds: 30) as $round) {
        $n = (int) $round->prompt->count;
        $answer = $round->item($round->solution->item_ids[0]);
        $numbers = array_map(static fn (RoundItemData $item): int => (int) $item->text, $round->items);
        $distances = array_map(static fn (int $number): int => abs($number - $n), $numbers);
        sort($distances);

        expect($round->solution->order)->toBe(SolutionOrder::Any)
            ->and($round->solution->item_ids)->toHaveCount(1)
            ->and($answer?->text)->toBe((string) $n)
            ->and(array_unique($numbers))->toHaveCount(4)
            ->and(min($numbers))->toBeGreaterThanOrEqual(1)
            ->and(max($numbers))->toBeLessThanOrEqual(10)
            ->and($distances[0])->toBe(0)
            ->and($distances[1])->toBe(1)
            ->and(max($distances))->toBeLessThanOrEqual(3);
    }
});

it('deals every number equally often, never the same N or the same picture twice in a row', function (): void {
    $rounds = countRounds('numbers-5', 'animals', rounds: 23);
    $counts = array_map(static fn (RoundData $round): int => (int) $round->prompt->count, $rounds);
    $pictures = array_map(static fn (RoundData $round): string => (string) $round->prompt->image, $rounds);
    $dealt = array_count_values($counts);

    ksort($dealt);

    expect(array_keys($dealt))->toBe([1, 2, 3, 4, 5])
        ->and(max($dealt) - min($dealt))->toBeLessThanOrEqual(1);

    foreach ($rounds as $index => $round) {
        if ($index > 0) {
            expect($counts[$index])->not->toBe($counts[$index - 1])
                ->and($pictures[$index])->not->toBe($pictures[$index - 1]);
        }
    }
});

it('never counts a teacher word or a word the owner hid', function (): void {
    $teacherPack = static fn (PackData $pack): PackData => TeacherPacks::merge($pack, [new WordData('Aysel', null, ['Ay', 'sel'], false)], ['word:alma']);
    $texts = array_map(static fn (RoundData $round): string => $round->prompt->text, countRounds(rounds: 30, packAs: $teacherPack));

    expect($texts)->not->toContain('Neçə «Aysel» var?')
        ->and($texts)->not->toContain('Neçə «alma» var?');
});

it('needs a pack that lists numbers and two things to count', function (): void {
    $content = app(ContentRepository::class);
    $fruit = $content->pack('fruit');
    $level = $content->level('numbers-5');
    $one = TeacherPacks::merge($fruit, [], array_map(static fn (WordData $word): string => $word->key(), array_slice($fruit->words, 1)));

    expect(Playable::reason(Mechanic::Count, $fruit, $level, ['a']))->toBeNull()
        ->and(Playable::reason(Mechanic::Count, $one, $level, Alphabet::letters()))
        ->toBe(__('games.setup.errors.too_few_pictures', ['count' => 2, 'found' => 1]))
        ->and(Playable::reason(Mechanic::Count, $content->pack('train'), $level, Alphabet::letters()))
        ->toBe(__('games.setup.errors.pack_level'))
        ->and(Playable::reason(Mechanic::Count, $fruit, $content->level('pictures'), Alphabet::letters()))
        ->toBe(__('games.setup.errors.level_mechanic'));
});

it('gives the same rounds for the same seed', function (): void {
    $strip = static fn (array $rounds): array => array_map(static function (RoundData $round): array {
        $array = $round->toArray();
        unset($array['id']);

        return $array;
    }, $rounds);

    expect($strip(countRounds(seed: 9)))->toBe($strip(countRounds(seed: 9)))
        ->and($strip(countRounds(seed: 9)))->not->toBe($strip(countRounds(seed: 10)));
});
