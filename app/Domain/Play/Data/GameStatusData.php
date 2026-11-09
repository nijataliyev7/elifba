<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Models\GameSession;

/**
 * A game's status and the epoch of the board allowed to write
 * (docs/architecture.md §4; zod `GameStatus`): what the claim, the status
 * change, `/sync` and its 409 answer carry.
 *
 * @phpstan-type GameStatusArray array{status: string, board_epoch: int}
 */
final readonly class GameStatusData
{
    public function __construct(
        public GameSessionStatus $status,
        public int $board_epoch,
    ) {}

    public static function of(GameSession $game): self
    {
        return new self($game->status, $game->board_epoch);
    }

    /**
     * @param  GameStatusArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(GameSessionStatus::from($data['status']), $data['board_epoch']);
    }

    /**
     * @return GameStatusArray
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'board_epoch' => $this->board_epoch,
        ];
    }
}
