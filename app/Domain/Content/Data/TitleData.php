<?php

namespace App\Domain\Content\Data;

/**
 * A title in both interface languages. Pack and level titles live in their
 * JSON files, not in `lang/` (docs/architecture.md §9).
 */
final readonly class TitleData
{
    public function __construct(
        public string $az,
        public string $en,
    ) {}

    /**
     * @param  array{az: string, en: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['az'], $data['en']);
    }

    /**
     * The title in an interface locale; Azerbaijani for any other.
     */
    public function in(string $locale): string
    {
        return $locale === 'en' ? $this->en : $this->az;
    }
}
