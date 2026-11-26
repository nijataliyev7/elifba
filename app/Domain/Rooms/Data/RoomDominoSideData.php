<?php

namespace App\Domain\Rooms\Data;

/**
 * One side of a domino game in the state (docs/architecture.md §12 "The
 * state's domino", ADR 038 decision 38): its seats, its account — the
 * balance, whether it is open, the points waiting and the tries (R20–R22)
 * — and its dense place by balance. The state lists the sides in the
 * server's order, by balance and then by side, so a page never sorts them.
 *
 * @phpstan-type RoomDominoSideArray array{side: int, seats: list<int>, balance: int, open: bool, waiting: int, tries: int, place: int}
 */
final readonly class RoomDominoSideData
{
    /**
     * @param  list<int>  $seats
     */
    public function __construct(
        public int $side,
        public array $seats,
        public int $balance,
        public bool $open,
        public int $waiting,
        public int $tries,
        public int $place,
    ) {}

    /**
     * @param  RoomDominoSideArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['side'], $data['seats'], $data['balance'], $data['open'], $data['waiting'], $data['tries'], $data['place']);
    }

    /**
     * @return RoomDominoSideArray
     */
    public function toArray(): array
    {
        return [
            'side' => $this->side,
            'seats' => $this->seats,
            'balance' => $this->balance,
            'open' => $this->open,
            'waiting' => $this->waiting,
            'tries' => $this->tries,
            'place' => $this->place,
        ];
    }
}
