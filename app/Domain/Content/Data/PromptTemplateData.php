<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;

/**
 * A child-facing prompt template from `content/prompts.json`, always
 * Azerbaijani (docs/architecture.md §3). The inserted text stands alone in
 * «» with no suffix attached, so vowel harmony never has to be computed:
 * `«{x}» hecasını tap!`. Not to be confused with Play's `PromptData`, the
 * prompt of one built round.
 */
final readonly class PromptTemplateData
{
    public const string PLACEHOLDER = '{x}';

    public function __construct(
        public Mechanic $mechanic,
        public Unit $unit,
        public string $text,
    ) {}

    /**
     * @param  array{mechanic: string, unit: string, text: string}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(Mechanic::from($data['mechanic']), Unit::from($data['unit']), $data['text']);
    }

    public function takesText(): bool
    {
        return str_contains($this->text, self::PLACEHOLDER);
    }

    /**
     * The prompt with the (already cased) target text inserted.
     */
    public function fill(string $target): string
    {
        return str_replace(self::PLACEHOLDER, $target, $this->text);
    }
}
