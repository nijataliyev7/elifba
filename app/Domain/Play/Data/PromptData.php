<?php

namespace App\Domain\Play\Data;

/**
 * The prompt of one built round (docs/architecture.md §2), always
 * Azerbaijani. Not to be confused with Content's `PromptTemplateData`, the
 * template it is filled from.
 *
 * `count` (R5, ADR 012) is how many times the board draws `image`: N in a
 * `count` round, null everywhere else. A snapshot stored before R5 has no
 * `count` and is read as null; stored rounds are never rewritten.
 *
 * @phpstan-type PromptArray array{text: string, show: string|null, image: string|null, count: int|null, audio: string|null, tail: string|null}
 * @phpstan-type StoredPromptArray array{text: string, show: string|null, image: string|null, count?: int|null, audio: string|null, tail: string|null}
 */
final readonly class PromptData
{
    /**
     * @param  string  $text  child-facing: "«Ə» hərfini tap!"
     * @param  string|null  $show  what to show big: "Ə", "ba", "pişik"; null when nothing is named (pop, build_word, count)
     * @param  string|null  $image  URL of the picture; null when the round shows none
     * @param  int|null  $count  how many times the board draws `image`: N in a count round, else null
     * @param  string|null  $audio  URL of the clip that says the prompt (`PromptVoice`, §3 "Voice"); null where there is none
     * @param  string|null  $tail  fixed punctuation after the slots: "." "?" "!"
     */
    public function __construct(
        public string $text,
        public ?string $show,
        public ?string $image,
        public ?int $count,
        public ?string $audio,
        public ?string $tail,
    ) {}

    /**
     * @param  StoredPromptArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['text'], $data['show'], $data['image'], $data['count'] ?? null, $data['audio'], $data['tail']);
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
            'count' => $this->count,
            'audio' => $this->audio,
            'tail' => $this->tail,
        ];
    }
}
