<?php

namespace App\Domain\Rankings\Support;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Rankings\Data\PeriodData;
use App\Domain\Rankings\Data\PodiumPlaceData;
use App\Domain\Rankings\Data\RankedStudentData;

/**
 * The podium of a class ranking (docs/architecture.md §5, §6): places 1–3,
 * each with every student tied on it. It reads `ClassRanking` and keeps its
 * dense places and Azerbaijani order: no second query, no ranking of its
 * own. Empty when nobody has points in the window; a student with 0 points
 * is never on it, and nothing here knows a last place.
 */
final class Podium
{
    public const int PLACES = 3;

    /**
     * @return list<PodiumPlaceData>
     */
    public static function for(SchoolClass $class, PeriodData $period): array
    {
        return self::of(ClassRanking::for($class, $period));
    }

    /**
     * Places 1–3 of a ranking as `ClassRanking` returns it.
     *
     * @param  list<RankedStudentData>  $ranking
     * @return list<PodiumPlaceData>
     */
    public static function of(array $ranking): array
    {
        $places = [];

        foreach ($ranking as $row) {
            if ($row->place !== null && $row->place >= 1 && $row->place <= self::PLACES) {
                $places[$row->place][] = $row;
            }
        }

        ksort($places);

        $podium = [];

        foreach ($places as $place => $rows) {
            $podium[] = new PodiumPlaceData($place, $rows[0]->points, $rows);
        }

        return $podium;
    }
}
