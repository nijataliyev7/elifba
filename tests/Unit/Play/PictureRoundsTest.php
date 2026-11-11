<?php

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\FindRoundBuilder;
use App\Domain\Play\Support\Mechanics\PopRoundBuilder;
use App\Domain\Play\Support\Playable;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\Voice;

/*
 * `find` and `pop` at a picture level (docs/architecture.md §2 "Picture and
 * number rounds", ADR 012): an item is a word of the pack shown as its
 * picture, the prompt names the word for the adult and never shows the
 * picture. The pool is the pack's words with a picture, as the game's owner
 * plays the pack: learned letters do not filter it, a teacher word (no
 * picture) never enters and a word the owner hid is gone.
 */

// Which clips are made is content: no clip here, so prompt.audio is null;
// tests/Feature/Play/PromptVoiceTest checks it with clips.
beforeEach(fn () => Voice::none($this->app));

/**
 * @param  list<string>|null  $learned  null = all 32
 * @return list<RoundData>
 */
function pictureRounds(string $level = 'pictures', Mechanic $mechanic = Mechanic::Find, string $pack = 'animals', ?array $learned = null, int $rounds = 10, int $seed = 11, ?Closure $packAs = null): array
{
    $content = app(ContentRepository::class);
    $setup = new GameSetupData($mechanic, $pack, $level, $rounds, LetterCase::Lower);
    $builder = $mechanic === Mechanic::Pop ? app(PopRoundBuilder::class) : app(FindRoundBuilder::class);
    $packData = $packAs instanceof Closure ? $packAs($content->pack($pack)) : $content->pack($pack);

    return $builder->build($setup, $packData, $learned ?? Alphabet::letters(), new Randomizer(new Mt19937($seed)));
}

/**
 * @param  list<RoundData>  $rounds
 * @return list<string>
 */
function pictureKeys(array $rounds): array
{
    return array_merge(...array_map(
        static fn (RoundData $round): array => array_map(static fn (RoundItemData $item): string => $item->content_key, $round->items),
        $rounds,
    ));
}

it('gives every item its picture and the prompt none', function (string $pack, string $level): void {
    $content = app(ContentRepository::class);
    $packData = $content->pack($pack);
    $items = $content->level($level)->items;

    foreach (pictureRounds($level, pack: $pack) as $round) {
        $target = $round->item($round->solution->item_ids[0]);

        expect($round->unit)->toBe(Unit::Picture)
            ->and($round->items)->toHaveCount($items)
            ->and($round->solution->order)->toBe(SolutionOrder::Any)
            ->and($round->solution->item_ids)->toHaveCount(1)
            ->and($round->slots)->toBe(0)
            ->and($round->prompt->image)->toBeNull()
            ->and($round->prompt->count)->toBeNull()
            ->and($round->prompt->audio)->toBeNull()
            ->and($round->prompt->show)->toBe($target?->text)
            ->and($round->prompt->text)->toBe("«{$target?->text}» hansıdır?")
            ->and(array_unique(array_map(static fn (RoundItemData $item): string => $item->text, $round->items)))->toHaveCount($items);

        foreach ($round->items as $item) {
            $word = $packData->word($item->text);

            expect($word)->not->toBeNull()
                ->and($item->text)->toBe($word?->text)
                ->and($item->content_key)->toBe('word:'.Az::lower($item->text))
                ->and($item->image)->toBe(ContentAssets::url((string) $word?->image, $packData->version));
        }
    }
})->with([
    'animals, easy' => ['animals', 'pictures-easy'],
    'animals' => ['animals', 'pictures'],
    'fruit, easy' => ['fruit', 'pictures-easy'],
    'fruit' => ['fruit', 'pictures'],
]);

it('says every name aloud in pop: every item is right and the prompt names none', function (): void {
    foreach (pictureRounds(mechanic: Mechanic::Pop, pack: 'fruit') as $round) {
        expect($round->solution->item_ids)->toBe($round->itemIds())
            ->and($round->prompt->text)->toBe('Hər şəklin adını de və vur!')
            ->and($round->prompt->show)->toBeNull()
            ->and($round->prompt->image)->toBeNull()
            ->and(array_filter($round->items, static fn (RoundItemData $item): bool => $item->image === null))->toBe([]);
    }
});

it('ignores the learned letters and the letter case', function (): void {
    $all = pictureRounds(learned: Alphabet::letters());
    $three = pictureRounds(learned: ['a', 'n', 't']);
    $shape = static fn (array $rounds): array => array_map(static fn (RoundData $round): array => [
        $round->prompt->text,
        array_map(static fn (RoundItemData $item): array => $item->toArray(), $round->items),
    ], $rounds);

    expect($shape($three))->toBe($shape($all))
        ->and(Playable::reason(Mechanic::Find, app(ContentRepository::class)->pack('animals'), app(ContentRepository::class)->level('pictures'), ['a', 'n', 't']))->toBeNull();

    $upper = app(FindRoundBuilder::class)->build(
        new GameSetupData(Mechanic::Find, 'animals', 'pictures', 10, LetterCase::Upper),
        app(ContentRepository::class)->pack('animals'),
        Alphabet::letters(),
        new Randomizer(new Mt19937(11)),
    );

    expect($shape($upper))->toBe($shape($all));
});

it('never takes a teacher word, which has no picture', function (): void {
    $word = new WordData('Aysel', null, ['Ay', 'sel'], false);
    $withWord = static fn (PackData $pack): PackData => TeacherPacks::merge($pack, [$word], []);

    expect(pictureKeys(pictureRounds(rounds: 30, packAs: $withWord)))->not->toContain('word:aysel')
        ->and(pictureKeys(pictureRounds(mechanic: Mechanic::Pop, rounds: 30, packAs: $withWord)))->not->toContain('word:aysel');
});

it('leaves out a word the owner hid', function (): void {
    $hideCat = static fn (PackData $pack): PackData => TeacherPacks::merge($pack, [], ['word:pişik']);
    $keys = pictureKeys(pictureRounds(rounds: 30, packAs: $hideCat));

    expect($keys)->not->toContain('word:pişik')
        ->and(pictureKeys(pictureRounds(rounds: 30)))->toContain('word:pişik');
});

it('refuses a pack with too few pictures, naming the counts', function (): void {
    $content = app(ContentRepository::class);
    $animals = $content->pack('animals');
    $keep = array_map(static fn (WordData $word): string => $word->key(), array_slice($animals->words, 0, 3));
    $hidden = array_values(array_diff(array_map(static fn (WordData $word): string => $word->key(), $animals->words), $keep));
    $short = TeacherPacks::merge($animals, [], $hidden);

    expect(Playable::reason(Mechanic::Find, $short, $content->level('pictures'), Alphabet::letters()))
        ->toBe(__('games.setup.errors.too_few_pictures', ['count' => 4, 'found' => 3]))
        ->and(Playable::reason(Mechanic::Find, $short, $content->level('pictures-easy'), Alphabet::letters()))->toBeNull();
});

it('never asks the same picture twice in a row, and asks every picture equally often', function (): void {
    $rounds = pictureRounds(rounds: 30, pack: 'fruit');
    $targets = array_map(static fn (RoundData $round): string => (string) $round->item($round->solution->item_ids[0])?->content_key, $rounds);

    foreach ($targets as $index => $target) {
        if ($index > 0) {
            expect($target)->not->toBe($targets[$index - 1]);
        }
    }

    $counts = array_count_values($targets);

    expect(max($counts) - min($counts))->toBeLessThanOrEqual(1);
});
