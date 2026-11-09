<?php

use App\Domain\Play\Support\SchoolCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;

/*
 * Baku is UTC+4 with no daylight saving: a Baku day starts at 20:00 UTC the
 * evening before, and the school week starts on Monday (ADR 005).
 */

it('dates a moment by the Baku calendar', function (string $utc, string $playedOn, string $weekStart): void {
    expect(SchoolCalendar::stamp(CarbonImmutable::parse($utc, 'UTC')))
        ->toBe(['played_on' => $playedOn, 'week_start' => $weekStart]);
})->with([
    // Tuesday 2026-11-10 in Baku.
    'noon in Baku' => ['2026-11-10 08:00:00', '2026-11-10', '2026-11-09'],
    'one second before Baku midnight' => ['2026-11-10 19:59:59', '2026-11-10', '2026-11-09'],
    'Baku midnight' => ['2026-11-10 20:00:00', '2026-11-11', '2026-11-09'],
    // Sunday 2026-11-15 → Monday 2026-11-16 in Baku.
    'Sunday evening in Baku' => ['2026-11-15 19:59:59', '2026-11-15', '2026-11-09'],
    'Monday 00:00 in Baku' => ['2026-11-15 20:00:00', '2026-11-16', '2026-11-16'],
    'a UTC Sunday that is already Monday in Baku' => ['2026-11-15 23:30:00', '2026-11-16', '2026-11-16'],
    'Monday morning in Baku, still Sunday in UTC' => ['2026-11-15 21:00:00', '2026-11-16', '2026-11-16'],
    'across a year' => ['2026-12-31 20:00:00', '2027-01-01', '2026-12-28'],
]);

it('starts the week on Monday whatever the interface language', function (): void {
    foreach (['az', 'en'] as $locale) {
        App::setLocale($locale);

        // Saturday 2026-11-14 in Baku.
        expect(SchoolCalendar::weekStartOf(CarbonImmutable::parse('2026-11-14 10:00:00', 'UTC'))->toDateString())->toBe('2026-11-09');
    }
});

it('knows today in Baku', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-11-10 20:00:00', 'UTC'));

    expect(SchoolCalendar::today()->toDateString())->toBe('2026-11-11')
        ->and(SchoolCalendar::timezone())->toBe('Asia/Baku');
});
