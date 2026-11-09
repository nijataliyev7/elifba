<?php

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\BuildSyllableRoundBuilder;
use Tests\Support\BuildRounds;

/*
 * `build_syllable` rounds from the real content (docs/architecture.md §2,
 * "The build builders"): a syllable of two or more letters built from its
 * letter tiles, one decoy letter from the confusables first, seeded, made
 * of learned letters only, cased on the server.
 */

/**
 * @param  list<string>|null  $learned
 * @return list<RoundData>
 */
function syllableRounds(string $level = 'syllables-open', ?array $learned = null, int $seed = 7, int $rounds = 10, LetterCase $case = LetterCase::Lower, string $pack = 'train'): array
{
    return BuildRounds::build(Mechanic::BuildSyllable, $level, $pack, $learned, $seed, $rounds, $case);
}

/**
 * The letters a decoy for this syllable is taken from first: the most
 * confusable learned letters not in the syllable, rank by rank.
 *
 * @param  list<string>  $learned
 * @return list<string>
 */
function nearestConfusables(string $syllable, array $learned): array
{
    $letters = array_unique(mb_str_split($syllable));

    for ($rank = 0; $rank < 4; $rank++) {
        $near = [];

        foreach ($letters as $letter) {
            $confusable = Alphabet::confusables($letter)[$rank] ?? null;

            if ($confusable !== null && in_array($confusable, $learned, true) && ! in_array($confusable, $letters, true)) {
                $near[] = $confusable;
            }
        }

        if ($near !== []) {
            return $near;
        }
    }

    return [];
}

it('builds the same rounds from the same seed, and others from another', function (string $level): void {
    expect(BuildRounds::payloads(syllableRounds($level, seed: 42)))->toBe(BuildRounds::payloads(syllableRounds($level, seed: 42)))
        ->and(BuildRounds::payloads(syllableRounds($level, seed: 42)))->not->toBe(BuildRounds::payloads(syllableRounds($level, seed: 43)));
})->with(['syllables-open', 'syllables-closed']);

it('never asks for the same syllable twice in a row and spreads the syllables evenly', function (string $level, ?array $learned): void {
    foreach ([1, 2, 3, 99, 2026] as $seed) {
        $targets = BuildRounds::targets(syllableRounds($level, $learned, $seed, rounds: 30));

        foreach (array_slice($targets, 1) as $index => $target) {
            expect($target)->not->toBe($targets[$index], "seed {$seed}, round {$index}");
        }

        $counts = array_count_values($targets);
        expect(max($counts) - min($counts))->toBeLessThanOrEqual(1);
    }
})->with([
    'short syllables' => ['syllables-open', null],
    'long syllables' => ['syllables-closed', null],
    'few letters learned' => ['syllables-open', ['a', 'n', 't', 'ə', 'b', 'q']],
]);

it('shapes a build_syllable round', function (string $level, string $pack): void {
    $content = app(ContentRepository::class);
    $template = $content->prompt(Mechanic::BuildSyllable, Unit::Syllable);

    foreach (syllableRounds($level, rounds: 30, pack: $pack) as $index => $round) {
        $syllable = (string) $round->prompt->show;
        $letters = array_values(array_unique(mb_str_split($syllable)));
        $ids = array_slice(range('a', 'h'), 0, count($letters) + 1);

        expect($round->index)->toBe($index)
            ->and($round->total)->toBe(30)
            ->and($round->mechanic)->toBe(Mechanic::BuildSyllable)
            ->and($round->unit)->toBe(Unit::Syllable)
            ->and($round->pack)->toBe($pack)
            ->and($round->level)->toBe($level)
            // The syllable's distinct letters and one decoy, ids in display order.
            ->and($round->itemIds())->toBe($ids)
            ->and(BuildRounds::texts($round))->toEqualCanonicalizing([...$letters, ...array_map(static fn ($item): string => $item->text, BuildRounds::decoys($round))])
            ->and(BuildRounds::decoys($round))->toHaveCount(1)
            ->and($round->solution->order)->toBe(SolutionOrder::Sequence)
            ->and(BuildRounds::answer($round))->toBe(mb_str_split($syllable))
            ->and($round->slots)->toBe(mb_strlen($syllable))
            ->and($round->slots)->toBe(count($round->solution->item_ids))
            ->and($round->prompt->text)->toBe($template->fill($syllable))
            ->and($round->prompt->image)->toBeNull()
            ->and($round->prompt->audio)->toBeNull()
            ->and($round->prompt->tail)->toBeNull()
            ->and(array_filter($round->items, static fn ($item): bool => $item->image !== null))->toBe([])
            ->and(BuildRounds::standsBuilt($round))->toBeFalse()
            ->and($round->effect)->toBe($content->pack($pack)->style->effect);

        foreach ($round->items as $item) {
            expect($item->content_key)->toBe('letter:'.$item->text);
        }
    }
})->with([
    'short syllables, train' => ['syllables-open', 'train'],
    'long syllables, fruit' => ['syllables-closed', 'fruit'],
    'long syllables, symbols' => ['syllables-closed', 'symbols'],
]);

it('reuses a letter tile when the syllable repeats a letter', function (): void {
    $rounds = syllableRounds('syllables-closed', rounds: 30, pack: 'fruit');
    $tut = array_values(array_filter($rounds, static fn (RoundData $round): bool => $round->prompt->show === 'tut'));

    expect($tut)->not->toBeEmpty();

    foreach ($tut as $round) {
        [$t, $u, $again] = $round->solution->item_ids;

        expect($round->items)->toHaveCount(3)
            ->and($round->slots)->toBe(3)
            ->and($again)->toBe($t)
            ->and($u)->not->toBe($t)
            ->and($round->item($t)?->text)->toBe('t')
            ->and(BuildRounds::texts($round))->toHaveCount(count(array_unique(BuildRounds::texts($round))));
    }
});

it('leaves out one-letter syllables', function (): void {
    $content = app(ContentRepository::class);
    $pool = $content->level('syllables-open')->keysFrom($content->pack('balloons'));
    $vowels = array_filter($pool, static fn (string $key): bool => mb_strlen(explode(':', $key)[1]) === 1);
    $built = array_map(
        static fn (string $key): string => explode(':', $key)[1],
        array_values(array_diff($pool, $vowels)),
    );

    // 30 rounds go through the whole deck of the 28 two-letter syllables.
    $targets = array_values(array_unique(array_map(
        static fn (string $target): string => str_replace(' ', '', $target),
        BuildRounds::targets(syllableRounds('syllables-open', rounds: 30, pack: 'balloons')),
    )));

    expect($vowels)->not->toBeEmpty()
        ->and($targets)->toEqualCanonicalizing($built);
});

it('uses only syllables and letters the class has learned', function (string $level): void {
    $learned = ['a', 'b', 'd', 'e', 'ə', 'i', 'k', 'l', 'm', 'n', 'r', 's', 't', 'u', 'y', 'z'];

    foreach ([1, 2, 3] as $seed) {
        foreach (syllableRounds($level, $learned, $seed, rounds: 20) as $round) {
            expect(array_diff(mb_str_split((string) $round->prompt->show), $learned))->toBe([]);

            foreach ($round->items as $item) {
                expect($learned)->toContain(explode(':', $item->content_key)[1]);
            }
        }
    }
})->with(['syllables-open', 'syllables-closed']);

it('takes the decoy from the most confusable learned letters first, never from the syllable', function (?array $learned): void {
    $letters = $learned ?? Alphabet::letters();

    foreach ([1, 2, 3] as $seed) {
        foreach (syllableRounds('syllables-open', $learned, $seed, rounds: 20) as $round) {
            $syllable = (string) $round->prompt->show;
            $decoy = BuildRounds::decoys($round)[0]->text;
            $near = nearestConfusables($syllable, $letters);

            expect(mb_str_split($syllable))->not->toContain($decoy)
                ->and($letters)->toContain($decoy);

            if ($near !== []) {
                expect(in_array($decoy, $near, true))->toBeTrue("{$decoy} for {$syllable}");
            }
        }
    }
})->with([
    'all letters' => [null],
    'few confusables learned' => [['a', 'b', 'k', 'l', 'm', 'n', 'q', 'r', 's', 't', 'y']],
]);

it('cases text on the server, with Azerbaijani İ and I', function (): void {
    $upper = syllableRounds('syllables-open', rounds: 30, case: LetterCase::Upper, pack: 'balloons');
    $lower = syllableRounds('syllables-open', rounds: 30, case: LetterCase::Lower, pack: 'balloons');

    $texts = static fn (array $rounds): array => array_merge(...array_map(BuildRounds::texts(...), $rounds));

    foreach ($upper as $round) {
        expect($round->prompt->show)->toBe(Az::upper((string) $round->prompt->show));

        foreach ($round->items as $item) {
            expect($item->text)->toBe(Az::upper(explode(':', $item->content_key)[1]));
        }
    }

    expect($texts($upper))->toContain('İ', 'I')
        ->and($texts($lower))->toContain('i', 'ı')
        ->and(array_map(static fn (RoundData $round): ?string => $round->prompt->show, $upper))->toContain('Mİ', 'MI');
});

it('names the counts in the reason, counting only syllables that get their decoy', function (): void {
    $content = app(ContentRepository::class);
    $builder = app(BuildSyllableRoundBuilder::class);
    $fruit = $content->pack('fruit');
    $open = $content->level('syllables-open');

    // With a and n only, «na» has no letter left for its decoy and «a» has one letter.
    expect($builder->shortage($fruit, $open, ['a', 'n']))
        ->toBe(__('games.setup.errors.too_few_syllables', ['count' => 2, 'found' => 0]))
        ->and($builder->shortage($fruit, $open, ['a', 'n', 's']))
        ->toBe(__('games.setup.errors.too_few_syllables', ['count' => 2, 'found' => 1]))
        ->and($builder->shortage($fruit, $content->level('words-short'), Alphabet::letters()))
        ->toBe(__('games.setup.errors.level_mechanic'))
        ->and($builder->shortage($fruit, $open, Alphabet::letters()))
        ->toBeNull();
});

it('refuses to build what the rules would have refused', function (): void {
    syllableRounds('syllables-open', ['a', 'n'], pack: 'fruit');
})->throws(LogicException::class);
