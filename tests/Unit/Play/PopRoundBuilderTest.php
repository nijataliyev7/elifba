<?php

use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Syllabifier;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\FindRoundBuilder;
use App\Domain\Play\Support\Mechanics\PopRoundBuilder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * `pop` rounds («Oxu və vur»; docs/architecture.md §2, "The pop builder"):
 * a find round whose solution is every item. The items come from the same
 * selection as find's (pool, deck, decoys, shortages), so the same seed
 * shows the same items; only the solution and the prompt differ.
 */

/**
 * @param  list<string>|null  $learned  null = all 32
 * @param  list<string>|null  $letters
 * @return list<RoundData>
 */
function popRounds(string $level, ?array $learned = null, int $seed = 7, int $rounds = 10, ?array $letters = null, string $pack = 'balloons', Mechanic $mechanic = Mechanic::Pop, ?Closure $packAs = null): array
{
    $content = app(ContentRepository::class);
    $unit = $content->level($level)->unit;
    $setup = new GameSetupData(
        mechanic: $mechanic,
        pack: $pack,
        level: $level,
        rounds: $rounds,
        letter_case: $unit === Unit::Letter ? LetterCase::Upper : LetterCase::Lower,
        letters: $letters,
    );
    $builder = $mechanic === Mechanic::Pop ? app(PopRoundBuilder::class) : app(FindRoundBuilder::class);
    $packData = $packAs instanceof Closure ? $packAs($content->pack($pack)) : $content->pack($pack);

    return $builder->build($setup, $packData, $learned ?? Alphabet::letters(), new Randomizer(new Mt19937($seed)));
}

/**
 * Each round's items, without the round id.
 *
 * @param  list<RoundData>  $rounds
 * @return list<list<array<string, mixed>>>
 */
function popItems(array $rounds): array
{
    return array_map(static fn (RoundData $round): array => array_map(static fn (RoundItemData $item): array => $item->toArray(), $round->items), $rounds);
}

it('shapes a pop round: the level\'s items, every one in the solution, no target and no picture', function (string $level, Unit $unit): void {
    $content = app(ContentRepository::class);
    $template = $content->prompt(Mechanic::Pop, $unit);
    $items = $content->level($level)->items;

    expect($template->takesText())->toBeFalse();

    foreach (popRounds($level, rounds: 12) as $index => $round) {
        $texts = array_map(static fn (RoundItemData $item): string => $item->text, $round->items);
        $keys = array_map(static fn (RoundItemData $item): string => $item->content_key, $round->items);

        expect($round->index)->toBe($index)
            ->and($round->total)->toBe(12)
            ->and($round->mechanic)->toBe(Mechanic::Pop)
            ->and($round->unit)->toBe($unit)
            ->and($round->items)->toHaveCount($items)
            ->and($round->itemIds())->toBe(array_slice(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 0, $items))
            ->and(array_unique($texts))->toHaveCount($items)
            ->and(array_unique($keys))->toHaveCount($items)
            ->and($round->solution->order)->toBe(SolutionOrder::Any)
            ->and($round->solution->item_ids)->toBe($round->itemIds())
            ->and($round->slots)->toBe(0)
            ->and($round->prompt->text)->toBe($template->text)
            ->and($round->prompt->show)->toBeNull()
            ->and($round->prompt->image)->toBeNull()
            ->and($round->prompt->audio)->toBeNull()
            ->and($round->prompt->tail)->toBeNull()
            ->and(array_filter($round->items, static fn (RoundItemData $item): bool => $item->image !== null))->toBe([])
            ->and($round->effect)->toBe($content->pack('balloons')->style->effect);

        foreach ($keys as $key) {
            expect($key)->toStartWith($unit->value.':');
        }
    }
})->with([
    'letters' => ['letters', Unit::Letter],
    'short syllables' => ['syllables-open', Unit::Syllable],
    'long syllables' => ['syllables-closed', Unit::Syllable],
    'short words' => ['words-short', Unit::Word],
    'long words' => ['words-long', Unit::Word],
]);

it('says «read and tap» for every unit, without naming an item', function (): void {
    $content = app(ContentRepository::class);

    expect($content->prompt(Mechanic::Pop, Unit::Letter)->text)->toBe('Hər hərfi oxu və vur!')
        ->and($content->prompt(Mechanic::Pop, Unit::Syllable)->text)->toBe('Hər hecanı oxu və vur!')
        ->and($content->prompt(Mechanic::Pop, Unit::Word)->text)->toBe('Hər sözü oxu və vur!');
});

it('builds the same rounds from the same seed, and others from another', function (string $level): void {
    $payloads = static fn (array $rounds): array => array_map(static fn (RoundData $round): array => $round->payload(), $rounds);

    expect($payloads(popRounds($level, seed: 42)))->toBe($payloads(popRounds($level, seed: 42)))
        ->and($payloads(popRounds($level, seed: 42)))->not->toBe($payloads(popRounds($level, seed: 43)));
})->with(['letters', 'syllables-open', 'syllables-closed', 'words-short', 'words-long']);

it('shows exactly the items find shows for the same seed', function (string $level, ?array $letters): void {
    foreach ([1, 7, 2026] as $seed) {
        expect(popItems(popRounds($level, seed: $seed, rounds: 30, letters: $letters)))
            ->toBe(popItems(popRounds($level, seed: $seed, rounds: 30, letters: $letters, mechanic: Mechanic::Find)), "seed {$seed}");
    }
})->with([
    'letters' => ['letters', null],
    'chosen letters' => ['letters', ['ə', 'i']],
    'syllables' => ['syllables-open', null],
    'words' => ['words-short', null],
]);

it('uses only the learned letters, shows every chosen letter in each round, and leads it with a chosen letter beside its confusables', function (): void {
    $learned = ['a', 'b', 'd', 'e', 'ə', 'i', 'ı', 'k', 'l', 'm', 'n', 'r', 's', 't', 'u', 'y', 'z'];
    $asKeys = static fn (array $letters): array => array_map(static fn (string $letter): string => "letter:{$letter}", $letters);

    foreach ([1, 2, 3] as $seed) {
        foreach (popRounds('letters', $learned, $seed, rounds: 20, letters: ['ə', 'i']) as $index => $round) {
            $keys = array_map(static fn (RoundItemData $item): string => $item->content_key, $round->items);
            // The deck dealt ə or i; the other one comes first among the decoys.
            $leads = array_filter(['ə', 'i'], static fn (string $lead): bool => in_array("letter:{$lead}", $keys, true)
                && array_diff($asKeys(array_values(array_intersect(Alphabet::confusables($lead), $learned))), $keys) === []);

            expect(array_diff($keys, $asKeys($learned)))->toBe([])
                ->and(array_diff($asKeys(['ə', 'i']), $keys))->toBe([], "seed {$seed}, round {$index}")
                ->and($leads)->not->toBeEmpty("seed {$seed}, round {$index}");
        }
    }
});

it('plays a teacher\'s own word', function (): void {
    $word = new WordData(text: 'pomidor', image: null, syllables: Syllabifier::split('pomidor'), overridden: false);
    $withWord = static fn ($pack) => TeacherPacks::merge($pack, [$word], []);
    $keys = array_merge(...array_map(
        static fn (RoundData $round): array => array_map(static fn (RoundItemData $item): string => $item->content_key, $round->items),
        popRounds('words-long', rounds: 30, pack: 'fruit', packAs: $withWord),
    ));

    expect($keys)->toContain('word:pomidor')
        ->and(array_merge(...array_map(
            static fn (RoundData $round): array => array_map(static fn (RoundItemData $item): string => $item->content_key, $round->items),
            popRounds('words-long', rounds: 30, pack: 'fruit'),
        )))->not->toContain('word:pomidor');
});

it('names the counts in the reason, as find does', function (): void {
    $content = app(ContentRepository::class);
    $pop = app(PopRoundBuilder::class);
    $find = app(FindRoundBuilder::class);
    $cases = [
        ['balloons', 'letters', ['a', 'b', 'ə'], null, __('games.setup.errors.too_few_letters', ['count' => 4])],
        ['balloons', 'letters', Alphabet::letters(), ['ə'], __('games.setup.errors.too_few_targets', ['count' => 2])],
        ['balloons', 'words-short', ['a', 'e'], null, __('games.setup.errors.too_few_words', ['count' => 4, 'found' => 0])],
        ['balloons', 'syllables-closed', ['a', 'e'], null, __('games.setup.errors.too_few_syllables', ['count' => 4, 'found' => 0])],
        ['balloons', 'sentences', Alphabet::letters(), null, __('games.setup.errors.level_mechanic')],
        ['balloons', 'letters', Alphabet::letters(), null, null],
        ['symbols', 'words-long', Alphabet::letters(), null, null],
    ];

    foreach ($cases as [$pack, $level, $learned, $letters, $reason]) {
        $args = [$content->pack($pack), $content->level($level), $learned, $letters];

        expect($pop->shortage(...$args))->toBe($reason, "{$pack} × {$level}")
            ->and($pop->shortage(...$args))->toBe($find->shortage(...$args));
    }
});

it('refuses to build what the rules would have refused', function (): void {
    popRounds('letters', ['a', 'b']);
})->throws(LogicException::class);
