<?php

namespace App\Domain\Play\Data;

use Carbon\CarbonImmutable;

/**
 * The skip board event (docs/architecture.md §4): the teacher passed on a
 * round. It closes the round with no points.
 *
 * @phpstan-type SkipArray array{event_id: string, round_id: string, client_at: string|null}
 */
final readonly class SkipData
{
    public function __construct(
        public string $event_id,
        public string $round_id,
        public ?CarbonImmutable $client_at,
    ) {}

    /**
     * @param  SkipArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['event_id'], $data['round_id'], ClientTime::parse($data['client_at']));
    }

    /**
     * @return SkipArray
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'round_id' => $this->round_id,
            'client_at' => ClientTime::format($this->client_at),
        ];
    }
}
