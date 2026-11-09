<?php

namespace App\Domain\Play\Data;

/**
 * Where one round stands (docs/architecture.md §2): the items hit correctly
 * so far, in order, its misses, and whether it is solved or skipped.
 *
 * @phpstan-type RoundProgressArray array{round_id: string, hit_item_ids: list<string>, misses: int, solved: bool, skipped: bool}
 */
final readonly class RoundProgressData
{
    /**
     * @param  list<string>  $hit_item_ids
     */
    public function __construct(
        public string $round_id,
        public array $hit_item_ids,
        public int $misses,
        public bool $solved,
        public bool $skipped,
    ) {}

    /**
     * @param  RoundProgressArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['round_id'], $data['hit_item_ids'], $data['misses'], $data['solved'], $data['skipped']);
    }

    /**
     * @return RoundProgressArray
     */
    public function toArray(): array
    {
        return [
            'round_id' => $this->round_id,
            'hit_item_ids' => $this->hit_item_ids,
            'misses' => $this->misses,
            'solved' => $this->solved,
            'skipped' => $this->skipped,
        ];
    }
}
