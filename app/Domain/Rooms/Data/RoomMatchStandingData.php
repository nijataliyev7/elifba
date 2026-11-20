<?php

namespace App\Domain\Rooms\Data;

use InvalidArgumentException;

/**
 * A seat's row of a match's own standing, at its end (docs/architecture.md
 * §12 "Board games", ADR 034 decision 25; zod `RoomMatchStanding`): the
 * points the match gave it — `MATCH_WIN`, `MATCH_DRAW` or nothing, less
 * its counted taunts, never below 0 — and its place by the outcome, never
 * by those points: the winner 1 and the loser 2, both 1 in a draw, none in
 * a game without a result.
 *
 * @phpstan-type RoomMatchStandingArray array{player_id: string, points: int, place: int|null}
 */
final readonly class RoomMatchStandingData
{
    public function __construct(
        public string $player_id,
        public int $points,
        public ?int $place,
    ) {
        if ($points < 0 || ($place !== null && $place !== 1 && $place !== 2)) {
            throw new InvalidArgumentException('A seat of a match has its points from 0 and place 1, 2 or none.');
        }
    }

    /**
     * @param  RoomMatchStandingArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['player_id'], $data['points'], $data['place']);
    }

    /**
     * @return RoomMatchStandingArray
     */
    public function toArray(): array
    {
        return [
            'player_id' => $this->player_id,
            'points' => $this->points,
            'place' => $this->place,
        ];
    }
}
