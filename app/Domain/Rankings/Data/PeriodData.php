<?php

namespace App\Domain\Rankings\Data;

use App\Domain\Language\Support\DateLabel;
use App\Domain\Play\Support\SchoolCalendar;
use App\Domain\Rankings\Enums\Period;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Which ranking a page shows (docs/architecture.md §5, §6): the query
 * `?period=day|week&date=YYYY-MM-DD` of the rankings page, and of the
 * podium page in A10. Both keys are optional: the defaults are the day and
 * today in Baku. Any date selects its Monday–Sunday week. A date after
 * today in Baku is invalid, since no round can have been solved then.
 *
 * A GET page never answers an invalid query with a validation error: it
 * redirects to the query's valid part (`valid()`), so the defaults replace
 * what was wrong and the URL always says what the page shows.
 */
final readonly class PeriodData
{
    /**
     * A date on the Baku calendar, 00:00 in `elifba.timezone`.
     */
    public CarbonImmutable $date;

    public function __construct(public Period $period, CarbonInterface $date)
    {
        $this->date = SchoolCalendar::dateOf($date);
    }

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'period' => ['sometimes', 'string', Rule::enum(Period::class)],
            'date' => ['sometimes', 'string', 'date_format:Y-m-d', 'before_or_equal:'.SchoolCalendar::today()->toDateString()],
        ];
    }

    /**
     * The query without its invalid `period` or `date`, so their defaults
     * apply; other keys are not this class's and stay. The result is the
     * input itself when both are valid or absent.
     *
     * @param  array<array-key, mixed>  $input
     * @return array<array-key, mixed>
     */
    public static function valid(array $input): array
    {
        $validator = Validator::make(array_intersect_key($input, self::rules()), self::rules());
        $validator->passes();

        return array_diff_key($input, $validator->errors()->messages());
    }

    /**
     * Builds from a query that passed the rules; a missing key takes its
     * default.
     *
     * @param  array<array-key, mixed>  $validated
     */
    public static function fromArray(array $validated): self
    {
        $period = $validated['period'] ?? null;
        $date = $validated['date'] ?? null;

        if (is_string($date)) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $date, SchoolCalendar::timezone());
        }

        return new self(
            period: is_string($period) ? Period::tryFrom($period) ?? Period::Day : Period::Day,
            date: $date instanceof CarbonImmutable ? $date : SchoolCalendar::today(),
        );
    }

    /**
     * Validates and builds in one step, for an entry point that may answer
     * with a validation error (a JSON endpoint).
     *
     * @param  array<array-key, mixed>  $input
     */
    public static function validate(array $input): self
    {
        return self::fromArray(Validator::make($input, self::rules())->validate());
    }

    public static function today(Period $period = Period::Day): self
    {
        return new self($period, SchoolCalendar::today());
    }

    /**
     * The first day of the window: the date, or its Monday.
     */
    public function first(): CarbonImmutable
    {
        return $this->period->first($this->date);
    }

    /**
     * The last day of the window: the date, or its Sunday.
     */
    public function last(): CarbonImmutable
    {
        return $this->period->last($this->date);
    }

    /**
     * "9 noyabr 2026", or the week "9–15 noyabr 2026", in the given
     * interface language.
     */
    public function label(string $locale): string
    {
        return DateLabel::range($this->first(), $this->last(), $locale);
    }

    /**
     * The window that holds today in Baku.
     */
    public function isCurrent(): bool
    {
        return $this->first()->equalTo($this->period->first(SchoolCalendar::today()));
    }

    /**
     * The window before: the day before, or the Monday before.
     */
    public function previous(): self
    {
        return new self($this->period, $this->first()->subDays($this->period->days()));
    }

    /**
     * The window after, from its first day; null when it has not started.
     */
    public function next(): ?self
    {
        $first = $this->first()->addDays($this->period->days());

        return $first->greaterThan(SchoolCalendar::today()) ? null : new self($this->period, $first);
    }

    /**
     * @return array{period: string, date: string}
     */
    public function toQuery(): array
    {
        return ['period' => $this->period->value, 'date' => $this->date->toDateString()];
    }
}
