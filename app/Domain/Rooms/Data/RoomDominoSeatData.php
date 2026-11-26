<?php

namespace App\Domain\Rooms\Data;

use App\Domain\Rooms\Enums\RoomSeatStanding;
use InvalidArgumentException;

/**
 * One seat of a domino game in the state (docs/architecture.md §12 "The
 * state's domino", ADR 038 decision 38): its number in the order of the
 * turns, its player (`room_players.id`, null for the computer's), its side,
 * how many tiles it holds — a count, never a tile — and how it stands.
 *
 * @phpstan-type RoomDominoSeatArray array{seat: int, player_id: string|null, side: int, tiles: int, standing: string}
 */
final readonly class RoomDominoSeatData
{
    public function __construct(
        public int $seat,
        public ?string $player_id,
        public int $side,
        public int $tiles,
        public RoomSeatStanding $standing,
    ) {
        if (($player_id === null) !== ($standing === RoomSeatStanding::Computer)) {
            throw new InvalidArgumentException('A seat without a player is the computer\'s, and only it.');
        }
    }

    /**
     * @param  RoomDominoSeatArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['seat'], $data['player_id'], $data['side'], $data['tiles'], RoomSeatStanding::from($data['standing']));
    }

    /**
     * @return RoomDominoSeatArray
     */
    public function toArray(): array
    {
        return [
            'seat' => $this->seat,
            'player_id' => $this->player_id,
            'side' => $this->side,
            'tiles' => $this->tiles,
            'standing' => $this->standing->value,
        ];
    }
}
