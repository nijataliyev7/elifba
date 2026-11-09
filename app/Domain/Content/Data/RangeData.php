<?php

namespace App\Domain\Content\Data;

/**
 * An inclusive count range in a level filter: syllables per word, words per
 * sentence.
 */
final readonly class RangeData
{
    public function __construct(
        public int $min,
        public int $max,
    ) {}

    /**
     * @param  array{min: int, max: int}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['min'], $data['max']);
    }

    public function contains(int $count): bool
    {
        return $count >= $this->min && $count <= $this->max;
    }
}
