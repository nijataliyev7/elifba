<?php

use App\Domain\Rankings\Data\PeriodData;
use App\Domain\Rankings\Enums\Period;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/*
 * The ranking query `?period=day|week&date=YYYY-MM-DD`
 * (docs/architecture.md §5, §6): the day and today in Baku by default, any
 * date selects its Monday–Sunday week, and nothing after today in Baku.
 * 2026-11-09 is a Monday.
 */

beforeEach(function (): void {
    // Wednesday 11 November 2026, 14:00 in Baku.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 10:00:00', 'UTC'));
});

it('defaults to today in Baku, by the day', function (): void {
    $period = PeriodData::fromArray([]);

    expect($period->period)->toBe(Period::Day)
        ->and($period->date->toDateString())->toBe('2026-11-11')
        ->and($period->date->timezoneName)->toBe('Asia/Baku')
        ->and($period->isCurrent())->toBeTrue();
});

it('takes today from the Baku calendar, not UTC', function (): void {
    // 20:30 UTC on Wednesday is 00:30 on Thursday in Baku.
    $this->travelTo(CarbonImmutable::parse('2026-11-11 20:30:00', 'UTC'));

    expect(PeriodData::fromArray([])->date->toDateString())->toBe('2026-11-12')
        ->and(PeriodData::valid(['date' => '2026-11-12']))->toBe(['date' => '2026-11-12']);
});

it('selects the Monday–Sunday week that holds any date', function (string $date): void {
    $period = PeriodData::fromArray(['period' => 'week', 'date' => $date]);

    expect($period->first()->toDateString())->toBe('2026-11-02')
        ->and($period->last()->toDateString())->toBe('2026-11-08')
        ->and($period->label('az'))->toBe('2–8 noyabr 2026');
})->with(['Monday' => '2026-11-02', 'Thursday' => '2026-11-05', 'Sunday' => '2026-11-08']);

it('names the window before and the window after until today', function (): void {
    $today = PeriodData::fromArray([]);
    $yesterday = $today->previous();
    $thisWeek = PeriodData::fromArray(['period' => 'week']);
    $lastWeek = $thisWeek->previous();

    expect($yesterday->date->toDateString())->toBe('2026-11-10')
        ->and($yesterday->isCurrent())->toBeFalse()
        ->and($yesterday->next()?->date->toDateString())->toBe('2026-11-11')
        ->and($today->next())->toBeNull()
        ->and($lastWeek->date->toDateString())->toBe('2026-11-02')
        ->and($lastWeek->next()?->date->toDateString())->toBe('2026-11-09')
        ->and($thisWeek->next())->toBeNull()
        ->and($thisWeek->isCurrent())->toBeTrue()
        // A past day of this week is in the current week.
        ->and(PeriodData::fromArray(['period' => 'week', 'date' => '2026-11-09'])->isCurrent())->toBeTrue();
});

it('keeps only the valid keys of a query', function (array $query, array $valid): void {
    expect(PeriodData::valid($query))->toBe($valid);
})->with([
    'nothing' => [[], []],
    'both valid' => [['period' => 'week', 'date' => '2026-11-03'], ['period' => 'week', 'date' => '2026-11-03']],
    'today' => [['date' => '2026-11-11'], ['date' => '2026-11-11']],
    'other keys stay' => [['page' => '2', 'period' => 'day'], ['page' => '2', 'period' => 'day']],
    'an unknown period' => [['period' => 'month', 'date' => '2026-11-03'], ['date' => '2026-11-03']],
    'a date in the future' => [['period' => 'week', 'date' => '2026-11-12'], ['period' => 'week']],
    'a date that does not exist' => [['date' => '2026-02-30'], []],
    'a date in another format' => [['date' => '11.11.2026'], []],
    'a relative date' => [['date' => 'yesterday'], []],
    'an array' => [['period' => ['day'], 'date' => ['2026-11-03']], []],
    'null' => [['period' => null, 'date' => null], []],
]);

it('refuses an invalid query where a validation error is the answer', function (): void {
    PeriodData::validate(['date' => '2026-11-12']);
})->throws(ValidationException::class);

it('builds the query back', function (): void {
    expect(PeriodData::validate(['period' => 'week', 'date' => '2026-11-03'])->toQuery())
        ->toBe(['period' => 'week', 'date' => '2026-11-03']);
});
