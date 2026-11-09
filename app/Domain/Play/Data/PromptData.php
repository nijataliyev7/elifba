<?php

namespace App\Domain\Play\Data;

/**
 * The prompt of one built round (docs/architecture.md §2), always
 * Azerbaijani. Not to be confused with Content's `PromptTemplateData`, the
 * template it is filled from.
 *
 * @phpstan-type PromptArray array{text: string, show: string|null, image: string|null, audio: string|null, tail: string|null}
 */
final readonly class PromptData
{
    /**
     * @param  string  $text  child-facing: "«Ə» hərfini tap!"
     * @param  string|null  $show  what to show big: "Ə", "ba"; null for picture prompts
     * @param  string|null  $image  URL of the picture; null when the round shows none
     * @param  string|null  $audio  URL; null in v1 (no recorded voice, §3)
     * @param  string|null  $tail  fixed punctuation after the slots: "." "?" "!"
     */
    public function __construct(
        public string $text,
        public ?string $show,
        public ?string $image,
        public ?string $audio,
        public ?string $tail,
    ) {}

    /**
     * @param  PromptArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['text'], $data['show'], $data['image'], $data['audio'], $data['tail']);
    }

    /**
     * @return PromptArray
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'show' => $this->show,
            'image' => $this->image,
            'audio' => $this->audio,
            'tail' => $this->tail,
        ];
    }
}
