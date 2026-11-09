<?php

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Enums\AnswerStatus;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Playable;
use App\Domain\Play\Support\RoundJudge;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\Support\BuildRounds;

/*
 * Every build mechanic works in every pack (docs/architecture.md §2, "The
 * build builders"): with all 32 letters learned, each level that allows it
 * builds ten rounds in either case, and the judge solves each round from
 * its own solution without a miss.
 */

it('builds ten solvable rounds in every pack and level with all letters learned', function (Mechanic $mechanic): void {
    $content = app(ContentRepository::class);
    $builder = Playable::builder($mechanic);
    $levels = array_filter($content->levels(), static fn (LevelData $level): bool => $level->allows($mechanic));

    expect($levels)->not->toBeEmpty()
        ->and($content->packs())->toHaveCount(6);

    foreach ($levels as $level) {
        foreach ($content->packs() as $pack) {
            $game = "{$mechanic->value} × {$level->key} × {$pack->key}";

            expect($builder->shortage($pack, $level, Alphabet::letters()))->toBeNull($game);

            foreach (LetterCase::cases() as $case) {
                $rounds = $builder->build(
                    new GameSetupData($mechanic, $pack->key, $level->key, 10, $case),
                    $pack,
                    Alphabet::letters(),
                    new Randomizer(new Mt19937(2026)),
                );

                expect($rounds)->toHaveCount(10, $game);

                foreach ($rounds as $round) {
                    expect($round->mechanic)->toBe($mechanic)
                        ->and($round->solution->order)->toBe(SolutionOrder::Sequence)
                        ->and($round->slots)->toBe(count($round->solution->item_ids))
                        ->and($round->items)->toHaveCount(count(array_unique($round->solution->item_ids)) + ($level->decoys ?? 0))
                        ->and(array_unique(BuildRounds::texts($round)))->toHaveCount(count($round->items))
                        ->and(BuildRounds::standsBuilt($round))->toBeFalse($game)
                        ->and($round->effect)->toBe($pack->style->effect);

                    $progress = [];
                    $misses = 0;

                    foreach ($round->solution->item_ids as $hit => $itemId) {
                        $verdict = RoundJudge::judge($round, $progress, $itemId);
                        $misses += $verdict->status === AnswerStatus::Miss ? 1 : 0;
                        $progress = $verdict->progress;

                        expect($verdict->status)->toBe(AnswerStatus::Correct, $game)
                            ->and($verdict->solved)->toBe($hit === count($round->solution->item_ids) - 1);
                    }

                    expect($misses)->toBe(0)
                        ->and($progress)->toBe($round->solution->item_ids);
                }
            }
        }
    }
})->with([
    'build_syllable' => [Mechanic::BuildSyllable],
    'build_word' => [Mechanic::BuildWord],
    'build_sentence' => [Mechanic::BuildSentence],
]);
