<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\AnswerStatus;

/**
 * What the server made of one board event (docs/architecture.md §4).
 * `points` is set only on the hit that solved its round; a `duplicate`
 * repeats `round_solved` and `points` of the event's first result.
 *
 * @phpstan-type AnswerResultArray array{event_id: string, status: string, round_solved: bool, points: int|null}
 */
final readonly class AnswerResultData
{
    public function __construct(
        public string $event_id,
        public AnswerStatus $status,
        public bool $round_solved = false,
        public ?int $points = null,
    ) {}

    /**
     * @param  AnswerResultArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['event_id'], AnswerStatus::from($data['status']), $data['round_solved'], $data['points']);
    }

    /**
     * @return AnswerResultArray
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'status' => $this->status->value,
            'round_solved' => $this->round_solved,
            'points' => $this->points,
        ];
    }
}
