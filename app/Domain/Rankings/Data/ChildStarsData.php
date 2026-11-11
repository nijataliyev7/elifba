<?php

namespace App\Domain\Rankings\Data;

/**
 * One child's stars (docs/architecture.md §5 "A child's stars", ADR 011):
 * today, this week, in all (the rounds still stored) and the last seven
 * Baku days, oldest first, each with its date label made on the server.
 * The child's own, never beside a sibling's: no place, no comparison.
 */
final readonly class ChildStarsData
{
    /**
     * @param  list<array{date: string, label: string, stars: int}>  $days
     */
    public function __construct(
        public int $today,
        public int $week,
        public int $total,
        public array $days,
    ) {}

    /**
     * @return array{today: int, week: int, total: int, days: list<array{date: string, label: string, stars: int}>}
     */
    public function toArray(): array
    {
        return [
            'today' => $this->today,
            'week' => $this->week,
            'total' => $this->total,
            'days' => $this->days,
        ];
    }
}
