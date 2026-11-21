<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomLetterStatus;

/**
 * The answer to a bought letter (docs/architecture.md §12 "«Anaqram»: a
 * clue, a shuffle and a bought letter (ADR 037)", ADR 037 decision 12; zod
 * `RoomLetterReceipt`, fixture tests/Fixtures/rooms/letter-receipt.json):
 * what was done with it, and nothing else — a purchase raises no revision,
 * so there is none to name. The buyer's page reads the letter, its orders
 * and the next price from the state it asks for next.
 *
 * @phpstan-type RoomLetterReceiptArray array{status: string}
 */
final readonly class RoomLetterReceiptData
{
    public function __construct(
        public RoomLetterStatus $status,
    ) {}

    /**
     * @param  RoomLetterReceiptArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomLetterStatus::from($data['status']));
    }

    /**
     * @return RoomLetterReceiptArray
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
        ];
    }
}
