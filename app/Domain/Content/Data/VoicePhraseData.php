<?php

namespace App\Domain\Content\Data;

/**
 * A phrase of `content/voice.json` the board says on its own, not a
 * prompt: a praise or retry phrase, the greeting or an end phrase
 * (docs/architecture.md §3 "Voice"), with the audio tag it is said with
 * (`excited` → `[excited]`).
 */
final readonly class VoicePhraseData
{
    public function __construct(
        public string $text,
        public string $style,
    ) {}

    /**
     * @param  array{text: string, style: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['text'], $data['style']);
    }
}
