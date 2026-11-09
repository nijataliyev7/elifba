<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Models\Student;

/**
 * Takes a student who left the class out of pickers and rankings.
 * `RestoreStudent` brings them back; `PruneArchived` deletes them after
 * `elifba.retention_days`.
 */
final readonly class ArchiveStudent
{
    public function handle(Student $student): Student
    {
        if ($student->archived_at === null) {
            $student->update(['archived_at' => now()]);
        }

        return $student;
    }
}
