<?php

namespace App\Domain\Play\Data;

/**
 * One thing on the board in a round (docs/architecture.md §2). Item texts
 * are unique within a round, so a tap on the phone names exactly one item.
 *
 * @phpstan-type RoundItemArray array{id: string, text: string, image: string|null, content_key: string}
 */
final readonly class RoundItemData
{
    /**
     * @param  string  $id  short, unique in the round: "a", "b", …
     * @param  string  $text  already cased by the server
     * @param  string|null  $image  URL of a picture; null for `find` (§2)
     * @param  string  $content_key  `letter:ə`, `syllable:ba`, `word:alma`, `sentence:{pack}:{id}`, `word:{text}` for a sentence's word
     */
    public function __construct(
        public string $id,
        public string $text,
        public ?string $image,
        public string $content_key,
    ) {}

    /**
     * @param  RoundItemArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['text'], $data['image'], $data['content_key']);
    }

    /**
     * @return RoundItemArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'image' => $this->image,
            'content_key' => $this->content_key,
        ];
    }
}
