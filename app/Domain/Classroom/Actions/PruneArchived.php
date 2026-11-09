<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use Illuminate\Support\Facades\Config;

/**
 * Deletes classes and students archived more than `elifba.retention_days`
 * ago (docs/architecture.md §7). A class takes its students with it.
 * Scheduled daily (`classroom:prune-archived` in routes/console.php).
 */
final readonly class PruneArchived
{
    /**
     * @return array{classes: int, students: int} how many rows were deleted
     */
    public function handle(): array
    {
        $cutoff = now()->subDays(Config::integer('elifba.retention_days'));

        $classes = SchoolClass::query()->archived()->where('archived_at', '<', $cutoff)->delete();
        $students = Student::query()->archived()->where('archived_at', '<', $cutoff)->delete();

        return ['classes' => (int) $classes, 'students' => (int) $students];
    }
}
