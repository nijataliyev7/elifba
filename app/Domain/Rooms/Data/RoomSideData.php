<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomMatchColor;
use InvalidArgumentException;

/**
 * A side of a match in the state (docs/architecture.md §12 "Board games",
 * ADR 034 decisions 17 and 23; zod `RoomSide`): its colour, the seat that
 * plays it (`room_players.id`), its time at the state's `server_ms` — or
 * at the end, once the match is over — and its pieces, men and kings
 * together, of which `kings` are kings. The strip's totals are the pieces.
 *
 * @phpstan-type RoomSideArray array{color: string, player_id: string, left_ms: int, pieces: int, kings: int}
 */
final readonly class RoomSideData
{
    public function __construct(
        public RoomMatchColor $color,
        public string $player_id,
        public int $left_ms,
        public int $pieces,
        public int $kings,
    ) {
        if ($left_ms < 0 || $pieces < 0 || $pieces > 12 || $kings < 0 || $kings > $pieces) {
            throw new InvalidArgumentException('A side has its time from 0, at most twelve pieces and no more kings than pieces.');
        }
    }

    /**
     * @param  RoomSideArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(RoomMatchColor::from($data['color']), $data['player_id'], $data['left_ms'], $data['pieces'], $data['kings']);
    }

    /**
     * @return RoomSideArray
     */
    public function toArray(): array
    {
        return [
            'color' => $this->color->value,
            'player_id' => $this->player_id,
            'left_ms' => $this->left_ms,
            'pieces' => $this->pieces,
            'kings' => $this->kings,
        ];
    }
}
