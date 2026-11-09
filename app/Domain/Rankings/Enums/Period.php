<?php

namespace App\Domain\Rankings\Enums;

use App\Domain\Play\Support\SchoolCalendar;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The window of a ranking (docs/architecture.md §5): a Baku day, or the
 * Monday–Sunday week that holds a date. Each filters `game_rounds` on one
 * column that `RecordBoardEvents` stamped when the round was solved.
 */
enum Period: string
{
    case Day = 'day';
    case Week = 'week';

    /**
     * The `game_rounds` column this window filters on.
     */
    public function column(): string
    {
        return match ($this) {
            self::Day => 'played_on',
            self::Week => 'week_start',
        };
    }

    /**
     * The first day of the window that holds the date: the day itself, or
     * its Monday.
     */
    public function first(CarbonInterface $date): CarbonImmutable
    {
        return match ($this) {
            self::Day => SchoolCalendar::dateOf($date),
            self::Week => SchoolCalendar::weekStartOf($date),
        };
    }

    /**
     * The last day of the window that holds the date: the day itself, or
     * its Sunday.
     */
    public function last(CarbonInterface $date): CarbonImmutable
    {
        return $this->first($date)->addDays($this->days() - 1);
    }

    public function days(): int
    {
        return match ($this) {
            self::Day => 1,
            self::Week => 7,
        };
    }
}
