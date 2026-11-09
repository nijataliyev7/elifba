<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Data\SentenceData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Enums\LetterCase;
use LogicException;

/**
 * Rounds of `build_sentence` («Cümlə qur»; docs/architecture.md §2, "The
 * build builders"): the child puts a sentence's words in order; the final
 * `.`, `?` or `!` stays fixed after the last slot (`prompt.tail`).
 *
 * - Targets: the level's sentences.
 * - Tiles: the words as written, so the first word keeps its capital in
 *   lower case; content keys `word:{lower}`.
 * - Decoys: words of the level's other sentences, written as in their own
 *   sentence, never a word of this one in another case.
 *
 * @phpstan-import-type Tile from BuildRoundBuilder
 */
final readonly class BuildSentenceRoundBuilder extends BuildRoundBuilder
{
    protected function mechanic(): Mechanic
    {
        return Mechanic::BuildSentence;
    }

    protected function tooFew(): string
    {
        return 'too_few_sentences';
    }

    protected function candidates(PackData $pack, LevelData $level, array $learned): array
    {
        return $level->keysFrom($pack, $learned);
    }

    protected function answer(string $target, PackData $pack): array
    {
        return array_map(self::word(...), self::sentence($target, $pack)->words());
    }

    protected function decoys(string $target, PackData $pack, LevelData $level, array $learned): array
    {
        $decoys = [];

        foreach ($level->keysFrom($pack, $learned) as $key) {
            if ($key !== $target) {
                array_push($decoys, ...array_map(self::word(...), self::sentence($key, $pack)->words()));
            }
        }

        return [$decoys];
    }

    protected function prompt(string $target, PackData $pack, LetterCase $case, PromptTemplateData $template): PromptData
    {
        return new PromptData(
            text: $template->text,
            show: null,
            image: null,
            audio: null,
            tail: self::sentence($target, $pack)->punctuation(),
        );
    }

    /**
     * @return Tile
     */
    private static function word(string $written): array
    {
        return ['key' => 'word:'.Az::lower($written), 'text' => $written];
    }

    private static function sentence(string $key, PackData $pack): SentenceData
    {
        foreach ($pack->sentences as $sentence) {
            if ($sentence->key() === $key) {
                return $sentence;
            }
        }

        throw new LogicException("{$pack->key} has no {$key}.");
    }
}
