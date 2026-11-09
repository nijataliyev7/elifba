<?php

namespace App\Domain\Play\Data;

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Play\Enums\GameSessionStatus;

/**
 * The game at the head of `GameStateData` (docs/architecture.md §2).
 *
 * @phpstan-type GameArray array{id: string, status: string, board_epoch: int, pack: string, mechanic: string}
 */
final readonly class GameData
{
    public function __construct(
        public string $id,
        public GameSessionStatus $status,
        public int $board_epoch,
        public string $pack,
        public Mechanic $mechanic,
    ) {}

    /**
     * @param  GameArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['id'],
            GameSessionStatus::from($data['status']),
            $data['board_epoch'],
            $data['pack'],
            Mechanic::from($data['mechanic']),
        );
    }

    /**
     * @return GameArray
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'board_epoch' => $this->board_epoch,
            'pack' => $this->pack,
            'mechanic' => $this->mechanic->value,
        ];
    }
}
