<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;

/**
 * `prompt.audio` of a built round (docs/architecture.md §3 "Voice", ADR
 * 015): the URL of the clip that says the prompt, or null when there is
 * none and the board is silent for it.
 *
 * A word is voiced only when it is a built-in word of the round's pack: a
 * teacher word (ADR 007) is a row, may be a child's name and has no clip,
 * even where a built-in word of another pack is spelled the same. Letters,
 * syllables and numbers are content, whatever word a syllable came from.
 */
final readonly class PromptVoice
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
    ) {}

    /**
     * @param  string|null  $target  the content key the prompt names (`word:dovşan`); null when it names none
     * @param  PackData  $pack  the pack as the game's owner plays it (`TeacherPacks`)
     */
    public function url(PromptTemplateData $template, ?string $target, PackData $pack): ?string
    {
        if (! $template->takesText()) {
            $target = null;
        }

        $builtIn = $this->content->hasPack($pack->key) ? $this->content->pack($pack->key) : null;

        if ($target !== null && str_starts_with($target, Unit::Word->value.':') && $builtIn?->word(substr($target, strlen(Unit::Word->value) + 1)) === null) {
            return null;
        }

        $line = $this->clips->prompt($template, $target, $builtIn);

        return $line === null ? null : $this->clips->url($line);
    }
}
