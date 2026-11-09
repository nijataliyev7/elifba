<?php

namespace App\Domain\Content\Data;

use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\Syllabifier;

/**
 * One word of a pack, as written (proper nouns capitalised), with its
 * syllables: the pack's override when it has one, the Syllabifier's split
 * otherwise (docs/architecture.md §3).
 */
final readonly class WordData
{
    /**
     * @param  list<string>  $syllables
     * @param  bool  $overridden  whether the syllables come from the pack's `syllables` override
     */
    public function __construct(
        public string $text,
        public ?string $image,
        public array $syllables,
        public bool $overridden,
    ) {}

    /**
     * @param  array{text: string, image?: string, syllables?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        $override = $data['syllables'] ?? null;

        return new self(
            text: $data['text'],
            image: $data['image'] ?? null,
            syllables: $override ?? Syllabifier::split($data['text']),
            overridden: $override !== null,
        );
    }

    /**
     * `word:{lower}`, the stable key stored with every answer.
     */
    public function key(): string
    {
        return 'word:'.Az::lower($this->text);
    }

    /**
     * The syllables joined with hyphens (`al-ma`), as a teacher reads and
     * types them.
     */
    public function hyphenated(): string
    {
        return implode('-', $this->syllables);
    }

    /**
     * The lowercase letters the word uses, for the learned-letters filter.
     *
     * @return list<string>
     */
    public function letters(): array
    {
        return array_values(array_unique(mb_str_split(Az::lower($this->text))));
    }
}
