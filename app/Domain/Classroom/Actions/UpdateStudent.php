<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Data\StudentData;
use App\Domain\Classroom\Models\Student;
use App\Domain\Classroom\Support\ClassLock;
use App\Domain\Classroom\Support\DisplayName;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a student's display name or avatar. The name stays unique among
 * the class's other active students, compared with Azerbaijani casing.
 */
final readonly class UpdateStudent
{
    /**
     * @throws ValidationException on `display_name` when another active student has the name
     */
    public function handle(Student $student, StudentData $data): Student
    {
        return DB::transaction(function () use ($student, $data): Student {
            ClassLock::acquire($student->school_class_id);

            if (isset(ClassLock::activeNames($student->school_class_id, $student->id)[DisplayName::key($data->display_name)])) {
                throw ValidationException::withMessages(['display_name' => __('students.errors.name_taken')]);
            }

            $student->update([
                'display_name' => $data->display_name,
                'avatar' => $data->avatar,
            ]);

            return $student;
        });
    }
}
