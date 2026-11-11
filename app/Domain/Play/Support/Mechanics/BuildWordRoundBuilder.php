<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Enums\LetterCase;
use LogicException;

/**
 * Rounds of `build_word` («Sözü yığ»; docs/architecture.md §2, "The build
 * builders"): the prompt shows a word's picture, the child builds the word
 * from syllable tiles. The picture is the question, so it never says the
 * word.
 *
 * - Targets: the level's words of two or more syllables, with a picture.
 *   A teacher's own word has none (ADR 007), so it never plays here.
 * - Tiles: the word's syllables as split from the word as written (its
 *   override when it has one), so «Bakı» gives «Ba», «kı» in lower case;
 *   `nə-nə` is one `nə` tile, used twice.
 * - Decoys: syllables of the level's other words with a picture, written
 *   lowercase; a teacher's word gives none either.
 *
 * @phpstan-import-type Tile from BuildRoundBuilder
 */
final readonly class BuildWordRoundBuilder extends BuildRoundBuilder
{
    protected function mechanic(): Mechanic
    {
        return Mechanic::BuildWord;
    }

    protected function tooFew(): string
    {
        return 'too_few_words';
    }

    protected function candidates(PackData $pack, LevelData $level, array $learned): array
    {
        return array_values(array_filter(
            $level->keysFrom($pack, $learned),
            static function (string $key) use ($pack): bool {
                $word = self::word($key, $pack);

                return count($word->syllables) >= 2 && $word->image !== null;
            },
        ));
    }

    protected function answer(string $target, PackData $pack): array
    {
        return array_map(self::syllable(...), self::word($target, $pack)->syllables);
    }

    protected function decoys(string $target, PackData $pack, LevelData $level, array $learned): array
    {
        $decoys = [];

        foreach ($level->keysFrom($pack, $learned) as $key) {
            $word = self::word($key, $pack);

            if ($key !== $target && $word->image !== null) {
                foreach ($word->syllables as $syllable) {
                    $decoys[] = self::syllable(Az::lower($syllable));
                }
            }
        }

        return [$decoys];
    }

    protected function prompt(string $target, PackData $pack, LetterCase $case, PromptTemplateData $template, ?string $audio): PromptData
    {
        $image = self::word($target, $pack)->image ?? throw new LogicException('A build_word target has no picture.');

        return new PromptData(
            text: $template->text,
            show: null,
            image: ContentAssets::url($image, $pack->version),
            count: null,
            audio: $audio,
            tail: null,
        );
    }

    /**
     * @return Tile
     */
    private static function syllable(string $written): array
    {
        return ['key' => 'syllable:'.Az::lower($written), 'text' => $written];
    }

    private static function word(string $key, PackData $pack): WordData
    {
        // The key's text stays out of the message: a teacher's word may be a child's name.
        return $pack->word(RoundParts::value($key)) ?? throw new LogicException("The pack \"{$pack->key}\" has no such word.");
    }
}
