<?php

namespace App\Domain\Play\Data;

/**
 * The answer of `POST /games/{game}/sync` (docs/architecture.md §4; zod
 * `SyncResponse`): one result per event, in order, and the game as it is
 * after them.
 *
 * @phpstan-import-type AnswerResultArray from AnswerResultData
 * @phpstan-import-type GameStatusArray from GameStatusData
 *
 * @phpstan-type SyncResultArray array{results: list<AnswerResultArray>, game: GameStatusArray}
 */
final readonly class SyncResultData
{
    /**
     * @param  list<AnswerResultData>  $results
     */
    public function __construct(
        public array $results,
        public GameStatusData $game,
    ) {}

    /**
     * @param  SyncResultArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            array_map(AnswerResultData::fromArray(...), $data['results']),
            GameStatusData::fromArray($data['game']),
        );
    }

    /**
     * @return SyncResultArray
     */
    public function toArray(): array
    {
        return [
            'results' => array_map(static fn (AnswerResultData $result): array => $result->toArray(), $this->results),
            'game' => $this->game->toArray(),
        ];
    }
}
