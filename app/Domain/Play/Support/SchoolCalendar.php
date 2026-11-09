<?php

namespace App\Domain\Play\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;

/**
 * School dates on the Baku calendar (docs/architecture.md §5, ADR 005).
 * Timestamps are stored in UTC; a solved round also stores `played_on`,
 * the Baku date of `solved_at`, and `week_start`, the Monday of that date
 * (ISO week). Rankings filter on these two columns only, so no SQL ever
 * converts a time zone.
 */
final class SchoolCalendar
{
    public static function timezone(): string
    {
        return Config::string('elifba.timezone');
    }

    /**
     * The school date of a moment: 00:00 of its day in Baku.
     */
    public static function dateOf(CarbonInterface $moment): CarbonImmutable
    {
        return $moment->toImmutable()->setTimezone(self::timezone())->startOfDay();
    }

    /**
     * The Monday of a date's week. The week start is not taken from Carbon's
     * locale (English weeks start on Sunday): school weeks start on Monday.
     */
    public static function weekStartOf(CarbonInterface $date): CarbonImmutable
    {
        $day = self::dateOf($date);

        return $day->subDays($day->dayOfWeekIso - 1);
    }

    public static function today(): CarbonImmutable
    {
        return self::dateOf(CarbonImmutable::now());
    }

    /**
     * What a solved round stores beside `solved_at`.
     *
     * @return array{played_on: string, week_start: string}
     */
    public static function stamp(CarbonInterface $solvedAt): array
    {
        return [
            'played_on' => self::dateOf($solvedAt)->toDateString(),
            'week_start' => self::weekStartOf($solvedAt)->toDateString(),
        ];
    }
}
