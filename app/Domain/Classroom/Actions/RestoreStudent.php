<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Models\Student;
use App\Domain\Classroom\Support\ClassLock;
use App\Domain\Classroom\Support\DisplayName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings an archived student back at the end of the class's order, with
 * their avatar. Blocked while an active student has the same name (compared
 * with Azerbaijani casing); the teacher renames one of them first.
 */
final readonly class RestoreStudent
{
    /**
     * @throws ValidationException on `display_name` when an active student has the name
     */
    public function handle(Student $student): Student
    {
        if ($student->archived_at === null) {
            return $student;
        }

        return DB::transaction(function () use ($student): Student {
            $class = ClassLock::acquire($student->school_class_id);

            if (isset(ClassLock::activeNames($class->id)[DisplayName::key($student->display_name)])) {
                throw ValidationException::withMessages(['display_name' => __('students.errors.restore_name_taken')]);
            }

            $student->update([
                'archived_at' => null,
                'position' => 1 + (int) $class->students()->active()->max('position'),
            ]);

            return $student;
        });
    }
}
