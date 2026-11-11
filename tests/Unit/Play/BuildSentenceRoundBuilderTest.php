<?php

use App\Domain\Content\Data\SentenceData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Mechanics\BuildSentenceRoundBuilder;
use Tests\Support\BuildRounds;
use Tests\Support\Voice;

/*
 * `build_sentence` rounds from the real content (docs/architecture.md §2,
 * "The build builders"): a sentence's words as written, put in order, the
 * final punctuation fixed after the last slot, with one decoy word of
 * another sentence of the level.
 */

// Which clips are made is content: no clip here, so prompt.audio is null;
// tests/Feature/Play/PromptVoiceTest checks it with clips.
beforeEach(fn () => Voice::none($this->app));

/**
 * @param  list<string>|null  $learned
 * @return list<RoundData>
 */
function sentenceRounds(?array $learned = null, int $seed = 7, int $rounds = 10, LetterCase $case = LetterCase::Lower, string $pack = 'fruit'): array
{
    return BuildRounds::build(Mechanic::BuildSentence, 'sentences', $pack, $learned, $seed, $rounds, $case);
}

/**
 * The sentence a round asks for: its answer and its tail, in either case.
 */
function askedSentence(RoundData $round): SentenceData
{
    $text = implode(' ', BuildRounds::answer($round)).$round->prompt->tail;

    foreach (app(ContentRepository::class)->pack($round->pack)->sentences as $sentence) {
        if ($sentence->text === $text || Az::upper($sentence->text) === $text) {
            return $sentence;
        }
    }

    throw new RuntimeException("No sentence reads {$text}.");
}

it('builds the same rounds from the same seed, and others from another', function (string $pack): void {
    expect(BuildRounds::payloads(sentenceRounds(seed: 42, pack: $pack)))->toBe(BuildRounds::payloads(sentenceRounds(seed: 42, pack: $pack)))
        ->and(BuildRounds::payloads(sentenceRounds(seed: 42, pack: $pack)))->not->toBe(BuildRounds::payloads(sentenceRounds(seed: 43, pack: $pack)));
})->with(['fruit', 'symbols']);

it('never asks for the same sentence twice in a row and spreads the sentences evenly', function (?array $learned, string $pack): void {
    foreach ([1, 2, 3, 99, 2026] as $seed) {
        $targets = BuildRounds::targets(sentenceRounds($learned, $seed, rounds: 30, pack: $pack));

        foreach (array_slice($targets, 1) as $index => $target) {
            expect($target)->not->toBe($targets[$index], "seed {$seed}, round {$index}");
        }

        $counts = array_count_values($targets);
        expect(max($counts) - min($counts))->toBeLessThanOrEqual(1);
    }
})->with([
    'all letters' => [null, 'fruit'],
    'two sentences' => [['a', 'ç', 'l', 'm', 'r', 't', 'u', 'ş'], 'balloons'],
]);

it('shapes a build_sentence round', function (string $pack): void {
    $content = app(ContentRepository::class);
    $template = $content->prompt(Mechanic::BuildSentence, Unit::Sentence);

    foreach (sentenceRounds(rounds: 30, pack: $pack) as $index => $round) {
        $sentence = askedSentence($round);
        $words = $sentence->words();

        expect($round->index)->toBe($index)
            ->and($round->total)->toBe(30)
            ->and($round->mechanic)->toBe(Mechanic::BuildSentence)
            ->and($round->unit)->toBe(Unit::Sentence)
            ->and($round->pack)->toBe($pack)
            ->and($round->level)->toBe('sentences')
            ->and($round->prompt->text)->toBe($template->text)
            ->and($round->prompt->show)->toBeNull()
            ->and($round->prompt->image)->toBeNull()
            ->and($round->prompt->audio)->toBeNull()
            // The final punctuation is fixed after the slots, never a tile.
            ->and($round->prompt->tail)->toBe($sentence->punctuation())
            ->and(array_filter(BuildRounds::texts($round), static fn (string $text): bool => preg_match('/[.!?]/u', $text) === 1))->toBe([])
            ->and(array_filter($round->items, static fn ($item): bool => $item->image !== null))->toBe([])
            // The sentence's words as written, first one capitalised, and one decoy.
            ->and(BuildRounds::answer($round))->toBe($words)
            ->and(mb_substr($words[0], 0, 1))->toBe(Az::upper(mb_substr($words[0], 0, 1)))
            ->and($round->itemIds())->toBe(array_slice(range('a', 'h'), 0, count(array_unique($words)) + 1))
            ->and(BuildRounds::decoys($round))->toHaveCount(1)
            ->and(BuildRounds::texts($round))->toHaveCount(count(array_unique(BuildRounds::texts($round))))
            ->and($round->solution->order)->toBe(SolutionOrder::Sequence)
            ->and($round->slots)->toBe(count($words))
            ->and($round->slots)->toBe(count($round->solution->item_ids))
            ->and(BuildRounds::standsBuilt($round))->toBeFalse()
            ->and($round->effect)->toBe($content->pack($pack)->style->effect);

        foreach ($round->items as $item) {
            expect($item->content_key)->toBe('word:'.Az::lower($item->text));
        }
    }
})->with(['fruit', 'cities', 'symbols']);

it('writes the words as the sentence does in lower case, all capitals in upper case', function (): void {
    $lower = sentenceRounds(rounds: 30, pack: 'cities');
    $upper = sentenceRounds(rounds: 30, case: LetterCase::Upper, pack: 'cities');

    foreach ($upper as $index => $round) {
        expect(BuildRounds::texts($round))->toBe(array_map(Az::upper(...), BuildRounds::texts($lower[$index])))
            ->and($round->solution)->toEqual($lower[$index]->solution);
    }

    // «Qız qalası»: a proper noun keeps its capital inside the sentence.
    $qız = array_values(array_filter($lower, static fn (RoundData $round): bool => askedSentence($round)->text === 'Bakıda Qız qalası var.'));

    expect($qız)->not->toBeEmpty()
        ->and(BuildRounds::answer($qız[0]))->toBe(['Bakıda', 'Qız', 'qalası', 'var']);
});

it('takes the decoy from another sentence of the level, never a word of this one in any case', function (): void {
    // «Alma qırmızıdır.» and «Ana alma aldı.» share «alma» in two cases.
    $learned = ['a', 'd', 'ı', 'l', 'm', 'n', 'q', 'r', 'z'];
    $content = app(ContentRepository::class);
    $sentences = array_map(
        static fn (string $key): string => explode(':', $key)[2],
        $content->level('sentences')->keysFrom($content->pack('fruit'), $learned),
    );

    expect($sentences)->toBe(['alma-qirmizidir', 'ana-alma-aldi']);

    foreach ([1, 2, 3, 4, 5] as $seed) {
        foreach (sentenceRounds($learned, $seed, rounds: 6) as $round) {
            expect(BuildRounds::decoys($round)[0]->text)->toBeIn(askedSentence($round)->id === 'alma-qirmizidir' ? ['Ana', 'aldı'] : ['qırmızıdır']);
        }
    }

    expect(array_map(
        static fn (RoundData $round): string => BuildRounds::decoys($round)[0]->text,
        sentenceRounds(['a', 'ç', 'l', 'm', 'r', 't', 'u', 'ş'], rounds: 6, pack: 'balloons'),
    ))->each->toBeIn(['Uçurtma', 'Şarlar']);
});

it('writes a decoy as the first sentence of the level that has it', function (string $pack): void {
    $content = app(ContentRepository::class);
    $level = $content->level('sentences');
    $sentences = array_values(array_filter($content->pack($pack)->sentences, $level->fitsSentence(...)));

    foreach ([1, 2, 3] as $seed) {
        foreach (sentenceRounds(seed: $seed, rounds: 20, pack: $pack) as $round) {
            $asked = askedSentence($round);
            $decoy = BuildRounds::decoys($round)[0]->text;
            $forms = [];

            foreach ($sentences as $sentence) {
                if ($sentence->id !== $asked->id) {
                    foreach ($sentence->words() as $word) {
                        $forms[Az::lower($word)] ??= $word;
                    }
                }
            }

            expect(array_map(Az::lower(...), $asked->words()))->not->toContain(Az::lower($decoy))
                ->and($forms[Az::lower($decoy)] ?? null)->toBe($decoy);
        }
    }
})->with(['fruit', 'cities']);

it('uses only sentences and words the class has learned', function (): void {
    $learned = ['a', 'b', 'd', 'e', 'ə', 'i', 'ı', 'k', 'l', 'm', 'n', 'q', 'r', 's', 't', 'u', 'y', 'z', 'ç', 'ş', 'ğ', 'v'];

    foreach ([1, 2, 3] as $seed) {
        $rounds = sentenceRounds($learned, $seed, rounds: 20);

        foreach ($rounds as $round) {
            expect(array_diff(askedSentence($round)->letters(), $learned))->toBe([]);

            foreach ($round->items as $item) {
                expect(array_diff(BuildRounds::letters($item->content_key), $learned))->toBe([], $item->content_key);
            }
        }
    }
});

it('names the counts in the reason, counting only sentences that get their decoy', function (): void {
    $content = app(ContentRepository::class);
    $builder = app(BuildSentenceRoundBuilder::class);
    $balloons = $content->pack('balloons');
    $sentences = $content->level('sentences');

    expect($builder->shortage($balloons, $sentences, ['a', 'e']))
        ->toBe(__('games.setup.errors.too_few_sentences', ['count' => 2, 'found' => 0]))
        // «Uçurtma uçur.» alone has no other sentence to take a decoy from.
        ->and($builder->shortage($balloons, $sentences, ['a', 'ç', 'm', 'r', 't', 'u']))
        ->toBe(__('games.setup.errors.too_few_sentences', ['count' => 2, 'found' => 0]))
        ->and($builder->shortage($balloons, $sentences, ['a', 'ç', 'l', 'm', 'r', 't', 'u', 'ş']))
        ->toBeNull()
        ->and($builder->shortage($balloons, $content->level('words-short'), Alphabet::letters()))
        ->toBe(__('games.setup.errors.level_mechanic'));
});

it('refuses to build what the rules would have refused', function (): void {
    sentenceRounds(['a', 'ç', 'm', 'r', 't', 'u'], pack: 'balloons');
})->throws(LogicException::class);
