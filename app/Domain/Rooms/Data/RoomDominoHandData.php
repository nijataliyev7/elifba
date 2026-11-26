<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomOpenerWhy;

/**
 * The hand of a domino game that is open, or was last, in the state (docs/
 * architecture.md §12 "The state's domino", ADR 038 decision 38): its
 * number from 1, when it opened, who opens it and why (R6–R9).
 *
 * @phpstan-type RoomDominoHandArray array{number: int, opens_ms: int, opener: int, why: string}
 */
final readonly class RoomDominoHandData
{
    public function __construct(
        public int $number,
        public int $opens_ms,
        public int $opener,
        public RoomOpenerWhy $why,
    ) {}

    /**
     * @param  RoomDominoHandArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['number'], $data['opens_ms'], $data['opener'], RoomOpenerWhy::from($data['why']));
    }

    /**
     * @return RoomDominoHandArray
     */
    public function toArray(): array
    {
        return [
            'number' => $this->number,
            'opens_ms' => $this->opens_ms,
            'opener' => $this->opener,
            'why' => $this->why->value,
        ];
    }
}
