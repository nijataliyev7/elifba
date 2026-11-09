<?php

namespace App\Domain\Content\Data;

use App\Domain\Language\Support\Az;

/**
 * One sentence of a pack: words separated by single spaces and one final
 * `.`, `!` or `?`, which stays fixed after the last slot in a build round
 * (docs/architecture.md §2).
 */
final readonly class SentenceData
{
    public function __construct(
        public string $pack,
        public string $id,
        public string $text,
    ) {}

    /**
     * @param  array{id: string, text: string}  $data
     */
    public static function fromArray(array $data, string $pack): self
    {
        return new self($pack, $data['id'], $data['text']);
    }

    /**
     * `sentence:{pack}:{id}`.
     */
    public function key(): string
    {
        return "sentence:{$this->pack}:{$this->id}";
    }

    /**
     * The words in order, without the final punctuation, as written.
     *
     * @return list<string>
     */
    public function words(): array
    {
        return explode(' ', mb_substr($this->text, 0, -1));
    }

    /**
     * The final `.`, `!` or `?`.
     */
    public function punctuation(): string
    {
        return mb_substr($this->text, -1);
    }

    /**
     * The lowercase letters the sentence uses, for the learned-letters filter.
     *
     * @return list<string>
     */
    public function letters(): array
    {
        return array_values(array_unique(mb_str_split(Az::lower(implode('', $this->words())))));
    }
}
