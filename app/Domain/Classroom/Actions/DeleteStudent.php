<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Models\Student;

/**
 * Deletes a student for good (docs/architecture.md §7). From A6 on, their
 * rounds and answers keep `student_id = null` and leave the rankings.
 */
final readonly class DeleteStudent
{
    public function handle(Student $student): void
    {
        $student->delete();
    }
}
