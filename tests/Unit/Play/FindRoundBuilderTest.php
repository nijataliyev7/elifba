<?php

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\FindRoundBuilder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
 * `find` rounds from the real content (docs/architecture.md §2): seeded,
 * made of learned letters only, never the same target twice in a row,
 * decoys for a letter from its confusables first, cased on the server, no
 * pictures.
 */

/**
 * @param  list<string>|null  $learned  null = all 32
 * @param  list<string>|null  $letters
 * @return list<RoundData>
 */
function findRounds(string $level, ?array $learned = null, int $seed = 7, int $rounds = 10, ?LetterCase $case = null, ?array $letters = null, string $pack = 'balloons'): array
{
    $unit = app(ContentRepository::class)->level($level)->unit;
    $setup = new GameSetupData(
        mechanic: Mechanic::Find,
        pack: $pack,
        level: $level,
        rounds: $rounds,
        letter_case: $case ?? ($unit === Unit::Letter ? LetterCase::Upper : LetterCase::Lower),
        letters: $letters,
    );

    return app(FindRoundBuilder::class)->build($setup, $learned ?? Alphabet::letters(), new Randomizer(new Mt19937($seed)));
}

/**
 * @param  list<RoundData>  $rounds
 * @return list<string>
 */
function targetKeys(array $rounds): array
{
    return array_map(static function (RoundData $round): string {
        $item = $round->item($round->solution->item_ids[0]);

        return $item->content_key ?? '';
    }, $rounds);
}

/**
 * @param  list<RoundData>  $rounds
 * @return list<mixed>
 */
function payloads(array $rounds): array
{
    return array_map(static fn (RoundData $round): array => $round->payload(), $rounds);
}

it('builds the same rounds from the same seed, and others from another', function (string $level): void {
    expect(payloads(findRounds($level, seed: 42)))->toBe(payloads(findRounds($level, seed: 42)))
        ->and(payloads(findRounds($level, seed: 42)))->not->toBe(payloads(findRounds($level, seed: 43)));
})->with(['letters', 'syllables-open', 'syllables-closed', 'words-short', 'words-long']);

it('never asks for the same target twice in a row and spreads the targets evenly', function (string $level, ?array $learned, ?array $letters): void {
    foreach ([1, 2, 3, 99, 2026] as $seed) {
        $targets = targetKeys(findRounds($level, $learned, $seed, rounds: 30, letters: $letters));

        foreach (array_slice($targets, 1) as $index => $target) {
            expect($target)->not->toBe($targets[$index], "seed {$seed}, round {$index}");
        }

        $counts = array_count_values($targets);
        expect(max($counts) - min($counts))->toBeLessThanOrEqual(1);
    }
})->with([
    'letters' => ['letters', null, null],
    'two chosen letters' => ['letters', null, ['ə', 'e']],
    'syllables' => ['syllables-open', null, null],
    'words' => ['words-short', null, null],
    'words, few letters learned' => ['words-short', ['a', 'r', 'ş', 't', 'o', 'p', 'q', 'ı', 'm', 'z', 'ı', 's', 'l', 'k', 'u', 'y', 'n', 'i'], null],
]);

it('shapes a find round', function (string $level, Unit $unit): void {
    $template = app(ContentRepository::class)->prompt(Mechanic::Find, $unit);
    $items = app(ContentRepository::class)->level($level)->items;

    foreach (findRounds($level, rounds: 12) as $index => $round) {
        $target = $round->item($round->solution->item_ids[0]);
        $texts = array_map(static fn ($item): string => $item->text, $round->items);

        expect($round->index)->toBe($index)
            ->and($round->total)->toBe(12)
            ->and($round->mechanic)->toBe(Mechanic::Find)
            ->and($round->unit)->toBe($unit)
            ->and($round->items)->toHaveCount($items)
            ->and($round->itemIds())->toBe(array_slice(['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h'], 0, $items))
            ->and(array_unique($texts))->toHaveCount($items)
            ->and($round->solution->order)->toBe(SolutionOrder::Any)
            ->and($round->solution->item_ids)->toHaveCount(1)
            ->and($round->slots)->toBe(0)
            ->and($target)->not->toBeNull()
            ->and($round->prompt->show)->toBe($target?->text)
            ->and($round->prompt->text)->toBe($template->fill((string) $target?->text))
            ->and($round->prompt->image)->toBeNull()
            ->and($round->prompt->audio)->toBeNull()
            ->and($round->prompt->tail)->toBeNull()
            ->and(array_filter($round->items, static fn ($item): bool => $item->image !== null))->toBe([])
            ->and($round->effect)->toBe(app(ContentRepository::class)->pack('balloons')->style->effect);

        foreach ($round->items as $item) {
            expect($item->content_key)->toStartWith($unit->value.':');
        }
    }
})->with([
    'letters' => ['letters', Unit::Letter],
    'short syllables' => ['syllables-open', Unit::Syllable],
    'long syllables' => ['syllables-closed', Unit::Syllable],
    'short words' => ['words-short', Unit::Word],
    'long words' => ['words-long', Unit::Word],
]);

it('uses only content made of the learned letters', function (string $level): void {
    $learned = ['a', 'b', 'd', 'e', 'ə', 'i', 'k', 'l', 'm', 'n', 'r', 's', 't', 'u', 'y', 'z'];

    foreach ([1, 2, 3] as $seed) {
        foreach (findRounds($level, $learned, $seed, rounds: 20) as $round) {
            foreach ($round->items as $item) {
                $letters = array_unique(mb_str_split(Az::lower(explode(':', $item->content_key, 2)[1])));

                expect(array_values(array_diff($letters, $learned)))->toBe([], $item->content_key);
            }
        }
    }
})->with(['letters', 'syllables-open', 'syllables-closed', 'words-short']);

it('asks only for the chosen letters, with decoys from the other learned letters', function (): void {
    $rounds = findRounds('letters', letters: ['ə', 'i'], rounds: 10);

    expect(array_values(array_unique(targetKeys($rounds))))->toEqualCanonicalizing(['letter:ə', 'letter:i']);

    foreach ($rounds as $round) {
        $target = $round->item($round->solution->item_ids[0]);
        $keys = array_map(static fn ($item): string => $item->content_key, $round->items);

        // The confusables come first among the decoys: ə → e, a; i → ı.
        $confusables = array_map(static fn (string $letter): string => "letter:{$letter}", Alphabet::confusables(explode(':', (string) $target?->content_key)[1]));

        expect(array_diff($confusables, $keys))->toBe([]);
    }
});

it('takes confusable decoys only when the class has learned them', function (): void {
    $learned = ['a', 'b', 'd', 'k', 'l', 'm'];

    foreach (findRounds('letters', $learned, rounds: 12) as $round) {
        $keys = array_map(static fn ($item): string => $item->content_key, $round->items);
        $target = (string) $round->item($round->solution->item_ids[0])?->content_key;

        foreach (Alphabet::confusables(explode(':', $target)[1]) as $confusable) {
            if (in_array($confusable, $learned, true)) {
                expect($keys)->toContain("letter:{$confusable}");
            }
        }

        expect(array_diff($keys, array_map(static fn (string $letter): string => "letter:{$letter}", $learned)))->toBe([]);
    }
});

it('cases text on the server, with Azerbaijani İ and I', function (): void {
    $upper = findRounds('letters', ['i', 'ı', 'a', 'ə'], rounds: 8);
    $lower = findRounds('letters', ['i', 'ı', 'a', 'ə'], rounds: 8, case: LetterCase::Lower);

    $texts = static fn (array $rounds): array => array_values(array_unique(array_merge(...array_map(
        static fn (RoundData $round): array => array_map(static fn ($item): string => $item->text, $round->items),
        $rounds,
    ))));

    expect($texts($upper))->toEqualCanonicalizing(['İ', 'I', 'A', 'Ə'])
        ->and($texts($lower))->toEqualCanonicalizing(['i', 'ı', 'a', 'ə']);
});

it('writes words as the pack spells them in lower case, all capitals in upper case', function (): void {
    $words = static fn (LetterCase $case): array => array_merge(...array_map(
        static fn (RoundData $round): array => array_map(static fn ($item): string => $item->text, $round->items),
        findRounds('words-short', rounds: 30, case: $case, pack: 'cities'),
    ));

    $lower = $words(LetterCase::Lower);
    $upper = $words(LetterCase::Upper);

    expect($lower)->toContain('Bakı')
        ->and($upper)->toContain('BAKI')
        ->and($upper)->toBe(array_map(Az::upper(...), $upper));
});

it('names the counts in the reason', function (): void {
    $content = app(ContentRepository::class);
    $builder = app(FindRoundBuilder::class);

    expect($builder->shortage($content->pack('balloons'), $content->level('letters'), ['a', 'b', 'ə']))
        ->toBe(__('games.setup.errors.too_few_letters', ['count' => 4]))
        ->and($builder->shortage($content->pack('balloons'), $content->level('letters'), Alphabet::letters(), ['ə']))
        ->toBe(__('games.setup.errors.too_few_targets', ['count' => 2]))
        ->and($builder->shortage($content->pack('balloons'), $content->level('words-short'), ['a', 'e']))
        ->toBe(__('games.setup.errors.too_few_words', ['count' => 4, 'found' => 0]))
        ->and($builder->shortage($content->pack('balloons'), $content->level('letters'), Alphabet::letters()))
        ->toBeNull()
        ->and($builder->shortage($content->pack('balloons'), $content->level('words-short'), Alphabet::letters()))
        ->toBeNull();
});

it('refuses to build what the rules would have refused', function (): void {
    findRounds('letters', ['a', 'b']);
})->throws(LogicException::class);
