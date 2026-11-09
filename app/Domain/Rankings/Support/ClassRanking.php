<?php

namespace App\Domain\Rankings\Support;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Language\Support\AzCollator;
use App\Domain\Play\Models\GameRound;
use App\Domain\Rankings\Data\PeriodData;
use App\Domain\Rankings\Data\RankedStudentData;
use App\Domain\Rankings\Enums\Period;
use Carbon\CarbonInterface;

/**
 * A class's daily or weekly ranking (docs/architecture.md §5, ADR 005),
 * derived on every read and never stored.
 *
 * One `GROUP BY student_id` sums `points` over the class's rounds whose
 * `played_on` is the day, or whose `week_start` is the Monday of the week,
 * as `RecordBoardEvents` stamped them on the Baku calendar — no date or
 * time-zone function in SQL. The sums are merged in PHP with the class's
 * active students:
 *
 * - more than 0 points: a dense place (`DenseRank`), the highest first,
 *   names inside a place in Azerbaijani order (`AzCollator`);
 * - 0 points: after them, without a place, in Azerbaijani order;
 * - archived students, deleted students (`student_id` null) and rounds the
 *   whole class solved (`student_id` null) never appear.
 */
final class ClassRanking
{
    /**
     * @return list<RankedStudentData>
     */
    public static function for(SchoolClass $class, PeriodData $period): array
    {
        $totals = self::totals($class, $period);

        $rows = array_map(
            static fn (Student $student): array => ['student' => $student, 'points' => $totals[$student->id] ?? 0],
            AzCollator::sortBy(
                $class->students()->active()->get(),
                static fn (Student $student): string => $student->display_name,
            ),
        );

        $ranked = array_values(array_filter($rows, static fn (array $row): bool => $row['points'] > 0));
        $unranked = array_values(array_filter($rows, static fn (array $row): bool => $row['points'] <= 0));

        // Stable: equal points keep the Azerbaijani order.
        usort($ranked, static fn (array $a, array $b): int => $b['points'] <=> $a['points']);
        $places = DenseRank::places(array_column($ranked, 'points'));

        return [
            ...array_map(
                static fn (array $row, int $place): RankedStudentData => new RankedStudentData($row['student'], $row['points'], $place),
                $ranked,
                $places,
            ),
            ...array_map(
                static fn (array $row): RankedStudentData => new RankedStudentData($row['student'], 0, null),
                $unranked,
            ),
        ];
    }

    /**
     * @return list<RankedStudentData>
     */
    public static function day(SchoolClass $class, CarbonInterface $date): array
    {
        return self::for($class, new PeriodData(Period::Day, $date));
    }

    /**
     * The Monday–Sunday week that holds the date.
     *
     * @return list<RankedStudentData>
     */
    public static function week(SchoolClass $class, CarbonInterface $date): array
    {
        return self::for($class, new PeriodData(Period::Week, $date));
    }

    /**
     * Points per credited student in the window. The sum comes back as an
     * int or a numeric string depending on the engine, so it is cast.
     *
     * @return array<string, int>
     */
    private static function totals(SchoolClass $class, PeriodData $period): array
    {
        $sums = GameRound::query()
            ->toBase()
            ->where('school_class_id', $class->id)
            ->where($period->period->column(), $period->first()->toDateString())
            ->whereNotNull('student_id')
            ->groupBy('student_id')
            ->select('student_id')
            ->selectRaw('sum(points) as total')
            ->pluck('total', 'student_id');

        $totals = [];

        foreach ($sums as $studentId => $total) {
            $totals[(string) $studentId] = is_numeric($total) ? (int) $total : 0;
        }

        return $totals;
    }
}
