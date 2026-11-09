<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\HitSource;
use Carbon\CarbonImmutable;

/**
 * One item touched, from the phone or the board (docs/architecture.md §2,
 * §4). Every id in it is untrusted until `RecordBoardEvents` checks it.
 *
 * @phpstan-type HitArray array{event_id: string, round_id: string, item_id: string, source: string, student_id: string|null, client_at: string|null}
 */
final readonly class HitData
{
    /**
     * @param  string  $event_id  a ULID made by the sender; the idempotency key
     * @param  string|null  $student_id  at the board; null = whole class (no one is credited)
     */
    public function __construct(
        public string $event_id,
        public string $round_id,
        public string $item_id,
        public HitSource $source,
        public ?string $student_id,
        public ?CarbonImmutable $client_at,
    ) {}

    /**
     * @param  HitArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            event_id: $data['event_id'],
            round_id: $data['round_id'],
            item_id: $data['item_id'],
            source: HitSource::from($data['source']),
            student_id: $data['student_id'],
            client_at: ClientTime::parse($data['client_at']),
        );
    }

    /**
     * @return HitArray
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->event_id,
            'round_id' => $this->round_id,
            'item_id' => $this->item_id,
            'source' => $this->source->value,
            'student_id' => $this->student_id,
            'client_at' => ClientTime::format($this->client_at),
        ];
    }
}
