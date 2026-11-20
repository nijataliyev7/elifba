<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomActStatus;

/**
 * The answer to a posted act (docs/architecture.md §12 "Board games", ADR
 * 034 decision 9; zod `RoomActReceipt`, fixture
 * tests/Fixtures/rooms/act-receipt.json): what was done with it and the
 * room's revision. The match as it now stands comes with the next state.
 *
 * @phpstan-type RoomActReceiptArray array{status: string, revision: int}
 */
final readonly class RoomActReceiptData
{
    public function __construct(
        public RoomActStatus $status,
        public int $revision,
    ) {}

    /**
     * @param  RoomActReceiptArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomActStatus::from($data['status']), $data['revision']);
    }

    /**
     * @return RoomActReceiptArray
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'revision' => $this->revision,
        ];
    }
}
