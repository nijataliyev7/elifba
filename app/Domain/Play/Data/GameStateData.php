<?php

namespace App\Domain\Play\Data;

/**
 * `GET /games/{game}/state` (docs/architecture.md §2, §4; zod `GameState`):
 * every round of the game and where each stands, so a reloaded board
 * resumes at the first open round. Also the base of the board's
 * `client-state` event (A7).
 *
 * @phpstan-import-type GameArray from GameData
 * @phpstan-import-type RoundArray from RoundData
 * @phpstan-import-type RoundProgressArray from RoundProgressData
 *
 * @phpstan-type GameStateArray array{game: GameArray, rounds: list<RoundArray>, progress: list<RoundProgressArray>}
 */
final readonly class GameStateData
{
    /**
     * @param  list<RoundData>  $rounds
     * @param  list<RoundProgressData>  $progress
     */
    public function __construct(
        public GameData $game,
        public array $rounds,
        public array $progress,
    ) {}

    /**
     * @param  GameStateArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            GameData::fromArray($data['game']),
            array_map(RoundData::fromArray(...), $data['rounds']),
            array_map(RoundProgressData::fromArray(...), $data['progress']),
        );
    }

    /**
     * @return GameStateArray
     */
    public function toArray(): array
    {
        return [
            'game' => $this->game->toArray(),
            'rounds' => array_map(static fn (RoundData $round): array => $round->toArray(), $this->rounds),
            'progress' => array_map(static fn (RoundProgressData $progress): array => $progress->toArray(), $this->progress),
        ];
    }
}
