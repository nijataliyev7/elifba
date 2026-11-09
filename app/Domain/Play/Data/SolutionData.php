<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\SolutionOrder;

/**
 * What solves a round (docs/architecture.md §2). For `sequence` the ids are
 * in order and may repeat: a reusable tile (`nə-nə` = ["a", "a"]).
 *
 * @phpstan-type SolutionArray array{order: string, item_ids: list<string>}
 */
final readonly class SolutionData
{
    /**
     * @param  list<string>  $item_ids
     */
    public function __construct(
        public SolutionOrder $order,
        public array $item_ids,
    ) {}

    /**
     * @param  SolutionArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(SolutionOrder::from($data['order']), $data['item_ids']);
    }

    /**
     * @return SolutionArray
     */
    public function toArray(): array
    {
        return [
            'order' => $this->order->value,
            'item_ids' => $this->item_ids,
        ];
    }
}
