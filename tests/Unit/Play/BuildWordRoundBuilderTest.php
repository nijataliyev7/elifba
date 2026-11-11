<?php

use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\BuildWordRoundBuilder;
use Tests\Support\BuildRounds;
use Tests\Support\Voice;

/*
 * `build_word` rounds from the real content (docs/architecture.md §2,
 * "The build builders"): a word of two or more syllables, asked by its
 * picture, built from its syllables as split from the word as written,
 * with one decoy syllable of another word of the level.
 */

// Which clips are made is content: no clip here, so prompt.audio is null;
// tests/Feature/Play/PromptVoiceTest checks it with clips.
beforeEach(fn () => Voice::none($this->app));

/**
 * @param  list<string>|null  $learned
 * @return list<RoundData>
 */
function wordRounds(string $level = 'words-short', ?array $learned = null, int $seed = 7, int $rounds = 10, LetterCase $case = LetterCase::Lower, string $pack = 'animals'): array
{
    return BuildRounds::build(Mechanic::BuildWord, $level, $pack, $learned, $seed, $rounds, $case);
}

/**
 * The word a round asks for, found by its picture.
 */
function askedWord(RoundData $round): WordData
{
    foreach (app(ContentRepository::class)->pack($round->pack)->words as $word) {
        if ($word->image !== null && str_starts_with((string) $round->prompt->image, ContentAssets::url($word->image))) {
            return $word;
        }
    }

    throw new RuntimeException("No word has the picture {$round->prompt->image}.");
}

it('builds the same rounds from the same seed, and others from another', function (string $level): void {
    expect(BuildRounds::payloads(wordRounds($level, seed: 42)))->toBe(BuildRounds::payloads(wordRounds($level, seed: 42)))
        ->and(BuildRounds::payloads(wordRounds($level, seed: 42)))->not->toBe(BuildRounds::payloads(wordRounds($level, seed: 43)));
})->with(['words-short', 'words-long']);

it('never asks for the same word twice in a row and spreads the words evenly', function (string $level, ?array $learned, string $pack): void {
    foreach ([1, 2, 3, 99, 2026] as $seed) {
        $targets = BuildRounds::targets(wordRounds($level, $learned, $seed, rounds: 30, pack: $pack));

        foreach (array_slice($targets, 1) as $index => $target) {
            expect($target)->not->toBe($targets[$index], "seed {$seed}, round {$index}");
        }

        $counts = array_count_values($targets);
        expect(max($counts) - min($counts))->toBeLessThanOrEqual(1);
    }
})->with([
    'short words' => ['words-short', null, 'animals'],
    'long words' => ['words-long', null, 'animals'],
    'few letters learned' => ['words-short', ['n', 'ə', 'b', 'a'], 'train'],
]);

it('shapes a build_word round', function (string $level, string $pack): void {
    $content = app(ContentRepository::class);
    $template = $content->prompt(Mechanic::BuildWord, Unit::Word);
    $version = $content->pack($pack)->version;

    foreach (wordRounds($level, rounds: 30, pack: $pack) as $index => $round) {
        $word = askedWord($round);
        $syllables = array_values(array_unique($word->syllables));

        expect($round->index)->toBe($index)
            ->and($round->total)->toBe(30)
            ->and($round->mechanic)->toBe(Mechanic::BuildWord)
            ->and($round->unit)->toBe(Unit::Word)
            ->and($round->pack)->toBe($pack)
            ->and($round->level)->toBe($level)
            // The picture is the question: no text says the word.
            ->and($round->prompt->text)->toBe($template->text)
            ->and($round->prompt->show)->toBeNull()
            ->and($round->prompt->image)->toBe(ContentAssets::url((string) $word->image, $version))
            ->and($round->prompt->audio)->toBeNull()
            ->and($round->prompt->tail)->toBeNull()
            ->and(array_filter($round->items, static fn ($item): bool => $item->image !== null))->toBe([])
            // The word's distinct syllables and one decoy, ids in display order.
            ->and($round->itemIds())->toBe(array_slice(range('a', 'h'), 0, count($syllables) + 1))
            ->and(BuildRounds::decoys($round))->toHaveCount(1)
            ->and(BuildRounds::texts($round))->toHaveCount(count(array_unique(BuildRounds::texts($round))))
            ->and($round->solution->order)->toBe(SolutionOrder::Sequence)
            ->and(BuildRounds::answer($round))->toBe($word->syllables)
            ->and($round->slots)->toBe(count($word->syllables))
            ->and($round->slots)->toBe(count($round->solution->item_ids))
            ->and(BuildRounds::standsBuilt($round))->toBeFalse()
            ->and($round->effect)->toBe($content->pack($pack)->style->effect);

        foreach ($round->items as $item) {
            expect($item->content_key)->toBe('syllable:'.Az::lower($item->text));
        }
    }
})->with([
    'short words, animals' => ['words-short', 'animals'],
    'long words, cities' => ['words-long', 'cities'],
    'long words, symbols' => ['words-long', 'symbols'],
]);

it('reuses a syllable tile when the word repeats a syllable', function (string $level, string $pack, string $text): void {
    $rounds = array_values(array_filter(
        wordRounds($level, rounds: 30, pack: $pack),
        static fn (RoundData $round): bool => askedWord($round)->text === $text,
    ));

    expect($rounds)->not->toBeEmpty();

    foreach ($rounds as $round) {
        $ids = $round->solution->item_ids;

        expect(BuildRounds::answer($round))->toBe(askedWord($round)->syllables)
            ->and(count(array_unique($ids)))->toBeLessThan(count($ids))
            ->and($round->items)->toHaveCount(count(array_unique($ids)) + 1);
    }
})->with([
    'nə-nə' => ['words-short', 'train', 'nənə'],
    'ba-ba' => ['words-short', 'train', 'baba'],
    'xa-rı-bül-bül' => ['words-long', 'symbols', 'xarıbülbül'],
]);

it('leaves out one-syllable words', function (): void {
    $content = app(ContentRepository::class);
    $pack = $content->pack('animals');
    $words = array_map(
        static fn (string $key): WordData => $pack->word(explode(':', $key)[1]) ?? throw new RuntimeException($key),
        $content->level('words-short')->keysFrom($pack),
    );
    $short = array_filter($words, static fn (WordData $word): bool => count($word->syllables) === 1);
    $built = array_map(static fn (WordData $word): string => $word->text, array_diff_key($words, $short));

    // 30 rounds go through the whole deck of the 21 two-syllable words.
    $asked = array_values(array_unique(array_map(
        static fn (RoundData $round): string => askedWord($round)->text,
        wordRounds('words-short', rounds: 30, pack: 'animals'),
    )));

    expect($short)->not->toBeEmpty()
        ->and($asked)->toEqualCanonicalizing(array_values($built));
});

it('uses only words and syllables the class has learned', function (string $level, string $pack): void {
    $learned = ['a', 'b', 'd', 'e', 'ə', 'i', 'ı', 'k', 'l', 'm', 'n', 'q', 'r', 's', 't', 'u', 'y', 'z', 'ç', 'v', 'ş', 'x'];

    foreach ([1, 2, 3] as $seed) {
        foreach (wordRounds($level, $learned, $seed, rounds: 20, pack: $pack) as $round) {
            expect(array_diff(askedWord($round)->letters(), $learned))->toBe([]);

            foreach ($round->items as $item) {
                expect(array_diff(BuildRounds::letters($item->content_key), $learned))->toBe([], $item->content_key);
            }
        }
    }
})->with([
    'short words' => ['words-short', 'animals'],
    'long words' => ['words-long', 'cities'],
]);

it('takes the decoy from the syllables of the level\'s other words, never one of the word\'s own', function (string $level, string $pack, LetterCase $case): void {
    $content = app(ContentRepository::class);
    $words = $content->pack($pack)->words;

    foreach ([1, 2, 3] as $seed) {
        foreach (wordRounds($level, seed: $seed, rounds: 20, case: $case, pack: $pack) as $round) {
            $word = askedWord($round);
            $decoy = BuildRounds::decoys($round)[0];
            $sources = array_filter(
                $words,
                static fn (WordData $other): bool => $other->text !== $word->text
                    && $content->level($level)->fitsWord($other)
                    && in_array('syllable:'.Az::lower($decoy->text), array_map(static fn (string $syllable): string => 'syllable:'.Az::lower($syllable), $other->syllables), true),
            );

            expect($sources)->not->toBeEmpty($decoy->text)
                ->and(array_map(Az::lower(...), $word->syllables))->not->toContain(Az::lower($decoy->text))
                // A decoy belongs to no word on the board, so it has no capital.
                ->and($decoy->text)->toBe($case->apply(Az::lower($decoy->text)));
        }
    }
})->with([
    'short words, cities, lower case' => ['words-short', 'cities', LetterCase::Lower],
    'long words, cities, upper case' => ['words-long', 'cities', LetterCase::Upper],
    'short words, animals' => ['words-short', 'animals', LetterCase::Lower],
]);

it('splits the word as written: «Ba», «kı» in lower case, «BA», «KI» in upper case', function (): void {
    $bakı = static fn (LetterCase $case): RoundData => array_values(array_filter(
        wordRounds('words-short', rounds: 30, case: $case, pack: 'cities'),
        static fn (RoundData $round): bool => askedWord($round)->text === 'Bakı',
    ))[0];

    $lower = $bakı(LetterCase::Lower);
    $upper = $bakı(LetterCase::Upper);

    expect(BuildRounds::answer($lower))->toBe(['Ba', 'kı'])
        ->and(array_map(static fn (string $id): ?string => $lower->item($id)?->content_key, $lower->solution->item_ids))->toBe(['syllable:ba', 'syllable:kı'])
        ->and(BuildRounds::answer($upper))->toBe(['BA', 'KI']);
});

it('cases text on the server, with Azerbaijani İ and I', function (): void {
    $ismayıllı = static fn (LetterCase $case): RoundData => array_values(array_filter(
        wordRounds('words-long', rounds: 30, case: $case, pack: 'cities'),
        static fn (RoundData $round): bool => askedWord($round)->text === 'İsmayıllı',
    ))[0];

    expect(BuildRounds::answer($ismayıllı(LetterCase::Lower)))->toBe(['İs', 'ma', 'yıl', 'lı'])
        ->and(BuildRounds::answer($ismayıllı(LetterCase::Upper)))->toBe(['İS', 'MA', 'YIL', 'LI']);

    foreach (wordRounds('words-long', rounds: 30, case: LetterCase::Upper, pack: 'cities') as $round) {
        foreach ($round->items as $item) {
            expect($item->text)->toBe(Az::upper($item->text));
        }
    }
});

it('names the counts in the reason, counting only words that get their decoy', function (): void {
    $content = app(ContentRepository::class);
    $builder = app(BuildWordRoundBuilder::class);
    $short = $content->level('words-short');

    // «nənə» alone has no other word to take a decoy from.
    expect($builder->shortage($content->pack('train'), $short, ['n', 'ə']))
        ->toBe(__('games.setup.errors.too_few_words', ['count' => 2, 'found' => 0]))
        // «arı» takes its decoy from the one-syllable «at», which is never asked.
        ->and($builder->shortage($content->pack('animals'), $short, ['a', 't', 'r', 'ı']))
        ->toBe(__('games.setup.errors.too_few_words', ['count' => 2, 'found' => 1]))
        ->and($builder->shortage($content->pack('train'), $short, ['n', 'ə', 'b', 'a']))
        ->toBeNull()
        ->and($builder->shortage($content->pack('train'), $content->level('sentences'), Alphabet::letters()))
        ->toBe(__('games.setup.errors.level_mechanic'));
});

it('refuses to build what the rules would have refused', function (): void {
    wordRounds('words-short', ['n', 'ə'], pack: 'train');
})->throws(LogicException::class);
