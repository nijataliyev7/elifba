<?php

use App\Domain\Language\Support\DateLabel;
use Carbon\CarbonImmutable;

/*
 * Dates formatted on the server in the interface language
 * (docs/architecture.md §3, §5): month names come from Carbon's locale,
 * and a range names each month and year once. 2026-11-09 is a Monday.
 */

function bakuDay(string $date): CarbonImmutable
{
    return CarbonImmutable::parse($date, 'Asia/Baku');
}

it('formats a day', function (string $locale, string $label): void {
    expect(DateLabel::day(bakuDay('2026-11-09'), $locale))->toBe($label);
})->with([
    'az' => ['az', '9 noyabr 2026'],
    'en' => ['en', '9 November 2026'],
]);

it('formats a range, naming each month and year once', function (string $from, string $to, string $locale, string $label): void {
    expect(DateLabel::range(bakuDay($from), bakuDay($to), $locale))->toBe($label);
})->with([
    'one day' => ['2026-11-09', '2026-11-09', 'az', '9 noyabr 2026'],
    'a week in one month' => ['2026-11-09', '2026-11-15', 'az', '9–15 noyabr 2026'],
    'a week across months' => ['2026-10-26', '2026-11-01', 'az', '26 oktyabr – 1 noyabr 2026'],
    'a week across years' => ['2026-12-28', '2027-01-03', 'az', '28 dekabr 2026 – 3 yanvar 2027'],
    'a week in one month, en' => ['2026-11-09', '2026-11-15', 'en', '9–15 November 2026'],
    'a week across months, en' => ['2026-10-26', '2026-11-01', 'en', '26 October – 1 November 2026'],
    'a week across years, en' => ['2026-12-28', '2027-01-03', 'en', '28 December 2026 – 3 January 2027'],
]);

it('does not take the locale from the date', function (): void {
    $date = bakuDay('2026-05-01')->locale('en');

    expect(DateLabel::day($date, 'az'))->toBe('1 may 2026');
});
