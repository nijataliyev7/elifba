<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Enums\LetterCase;

/**
 * Rounds of `build_syllable` («Heca qur», the syllable train with the train
 * pack; docs/architecture.md §2, "The build builders"): the prompt shows a
 * syllable, the child builds it from letter tiles.
 *
 * - Targets: the level's syllables of two or more letters; a one-letter
 *   syllable would be a one-slot `find`.
 * - Tiles: the syllable's letters (`tut` → `t`, `u`, `t`: one `t` tile,
 *   used twice).
 * - Decoys: confusables of its letters among the learned letters, the most
 *   confusable of each letter first, then other learned letters; never a
 *   letter of the syllable.
 *
 * @phpstan-import-type Tile from BuildRoundBuilder
 */
final readonly class BuildSyllableRoundBuilder extends BuildRoundBuilder
{
    protected function mechanic(): Mechanic
    {
        return Mechanic::BuildSyllable;
    }

    protected function tooFew(): string
    {
        return 'too_few_syllables';
    }

    protected function candidates(PackData $pack, LevelData $level, array $learned): array
    {
        return array_values(array_filter(
            $level->keysFrom($pack, $learned),
            static fn (string $key): bool => mb_strlen(RoundParts::value($key)) >= 2,
        ));
    }

    protected function answer(string $target, PackData $pack): array
    {
        return array_map(self::letter(...), mb_str_split(RoundParts::value($target)));
    }

    protected function decoys(string $target, PackData $pack, LevelData $level, array $learned): array
    {
        $ranks = [];

        foreach (array_unique(mb_str_split(RoundParts::value($target))) as $letter) {
            foreach (Alphabet::confusables($letter) as $rank => $confusable) {
                if (in_array($confusable, $learned, true)) {
                    $ranks[$rank][] = self::letter($confusable);
                }
            }
        }

        ksort($ranks);

        return [...array_values($ranks), array_map(self::letter(...), Alphabet::sort($learned))];
    }

    protected function prompt(string $target, PackData $pack, LetterCase $case, PromptTemplateData $template): PromptData
    {
        $show = $case->apply(RoundParts::value($target));

        return new PromptData(text: $template->fill($show), show: $show, image: null, audio: null, tail: null);
    }

    /**
     * @return Tile
     */
    private static function letter(string $letter): array
    {
        return ['key' => "letter:{$letter}", 'text' => $letter];
    }
}
