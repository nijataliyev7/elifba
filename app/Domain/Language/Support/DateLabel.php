<?php

namespace App\Domain\Language\Support;

use Carbon\CarbonInterface;

/**
 * Dates as the interface shows them, formatted on the server with Carbon's
 * month names in the interface language (docs/architecture.md §3, §5): a
 * day is "9 noyabr 2026"; a range says each month and year once — "9–15
 * noyabr 2026", "26 oktyabr – 1 noyabr 2026", "28 dekabr 2026 – 3 yanvar
 * 2027". TypeScript never formats a date or names a month.
 */
final class DateLabel
{
    public static function day(CarbonInterface $date, string $locale): string
    {
        return $date->locale($locale)->translatedFormat('j F Y');
    }

    /**
     * From the first day to the last, both included; one day when they are
     * the same date.
     */
    public static function range(CarbonInterface $first, CarbonInterface $last, string $locale): string
    {
        $from = $first->locale($locale);
        $to = $last->locale($locale);

        return match (true) {
            $from->isSameDay($to) => self::day($to, $locale),
            $from->isSameMonth($to) => $from->format('j').'–'.self::day($to, $locale),
            $from->isSameYear($to) => $from->translatedFormat('j F').' – '.self::day($to, $locale),
            default => self::day($from, $locale).' – '.self::day($to, $locale),
        };
    }
}
