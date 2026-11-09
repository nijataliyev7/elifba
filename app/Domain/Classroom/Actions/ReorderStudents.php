<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Data\ReorderStudentsData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Classroom\Support\ClassLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the order of a class. The ids must be exactly the class's active
 * students, each once: they are not route-bound, so this check is what keeps
 * another class's (or another teacher's) students out.
 */
final readonly class ReorderStudents
{
    /**
     * @throws ValidationException on `student_ids` when the ids are not exactly the active students
     */
    public function handle(SchoolClass $class, ReorderStudentsData $data): void
    {
        DB::transaction(function () use ($class, $data): void {
            ClassLock::acquire($class->id);

            /** @var array<string, int> $current id => position */
            $current = $class->students()->active()->pluck('position', 'id')->all();
            $given = $data->student_ids;

            if (count($given) !== count($current)
                || count(array_unique($given)) !== count($given)
                || array_diff($given, array_keys($current)) !== []) {
                throw ValidationException::withMessages(['student_ids' => __('students.errors.order_mismatch')]);
            }

            foreach ($given as $index => $id) {
                if ($current[$id] !== $index + 1) {
                    // The order is not an edit of the student: `updated_at` stays.
                    Student::query()->whereKey($id)->toBase()->update(['position' => $index + 1]);
                }
            }
        });
    }
}
