<?php

namespace App\Domain\Rankings\Support;

use App\Domain\Family\Models\Child;
use App\Domain\Language\Support\DateLabel;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Support\SchoolCalendar;
use App\Domain\Rankings\Data\ChildStarsData;
use App\Models\User;
use Illuminate\Database\Query\Builder;

/**
 * A parent's child's stars (docs/architecture.md §5 "A child's stars",
 * ADR 011), derived on every read and never stored. A star is a point of a
 * solved home round (`PointsRule`, unchanged), always the game's child's:
 * sums of `game_rounds.points` by `child_id`, on the Baku `played_on` and
 * `week_start` the rounds already carry — no date or time-zone function in
 * SQL. There is no ranking, no place and no podium for a child: nothing
 * here orders children by stars or compares them, and the class rankings
 * never see a home round (they filter on `school_class_id`).
 */
final class ChildStars
{
    /**
     * Today, this week, in all and the last seven days of one child.
     */
    public static function of(Child $child, ?string $locale = null): ChildStarsData
    {
        $locale ??= app()->getLocale();
        $today = SchoolCalendar::today();
        $first = $today->subDays(6);

        $byDay = self::cast(self::rounds($child->id)
            ->where('played_on', '>=', $first->toDateString())
            ->where('played_on', '<=', $today->toDateString())
            ->groupBy('played_on')
            ->select('played_on')
            ->selectRaw('sum(points) as total')
            ->pluck('total', 'played_on')
            ->all());

        $days = [];

        for ($day = $first; $day->lessThanOrEqualTo($today); $day = $day->addDay()) {
            $date = $day->toDateString();
            $days[] = ['date' => $date, 'label' => DateLabel::day($day, $locale), 'stars' => $byDay[$date] ?? 0];
        }

        return new ChildStarsData(
            today: $byDay[$today->toDateString()] ?? 0,
            week: self::sum(self::rounds($child->id)->where('week_start', SchoolCalendar::weekStartOf($today)->toDateString())),
            total: self::sum(self::rounds($child->id)),
            days: $days,
        );
    }

    /**
     * Today's stars of each of a parent's children, by child id, in one
     * grouped query; a child without stars today has 0.
     *
     * @return array<string, int>
     */
    public static function today(User $parent): array
    {
        $children = Child::query()->ownedBy($parent)->pluck('id')->all();

        if ($children === []) {
            return [];
        }

        $sums = self::cast(GameRound::query()
            ->toBase()
            ->whereIn('child_id', $children)
            ->where('played_on', SchoolCalendar::today()->toDateString())
            ->groupBy('child_id')
            ->select('child_id')
            ->selectRaw('sum(points) as total')
            ->pluck('total', 'child_id')
            ->all());

        $today = [];

        foreach ($children as $id) {
            $today[(string) $id] = $sums[(string) $id] ?? 0;
        }

        return $today;
    }

    /**
     * The solved rounds of one child (an open or skipped round has 0
     * points and no date).
     */
    private static function rounds(string $childId): Builder
    {
        return GameRound::query()->toBase()->where('child_id', $childId)->whereNotNull('solved_at');
    }

    /**
     * An int or a numeric string, depending on the engine.
     */
    private static function sum(Builder $query): int
    {
        return (int) $query->sum('points');
    }

    /**
     * The sums come back as ints or numeric strings depending on the
     * engine, so they are cast.
     *
     * @param  array<array-key, mixed>  $sums
     * @return array<string, int>
     */
    private static function cast(array $sums): array
    {
        $cast = [];

        foreach ($sums as $key => $total) {
            $cast[(string) $key] = is_numeric($total) ? (int) $total : 0;
        }

        return $cast;
    }
}
