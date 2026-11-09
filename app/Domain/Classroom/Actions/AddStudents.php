<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Data\AddStudentsData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Classroom\Support\ClassLock;
use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Content\Support\Avatars;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds the names the teacher confirmed in the paste preview. A name must be
 * unique among the class's active students, compared with Azerbaijani casing
 * ("İlkin" = "ilkin"), and appear once in the list; otherwise nothing is
 * added. Avatars are given least-used first within the class, in the order
 * of `content/avatars.json`; positions continue after the last student.
 */
final readonly class AddStudents
{
    /**
     * @return Collection<int, Student>
     *
     * @throws ValidationException on `names.{i}` when a name is taken or repeated
     */
    public function handle(SchoolClass $class, AddStudentsData $data): Collection
    {
        return DB::transaction(function () use ($class, $data): Collection {
            ClassLock::acquire($class->id);

            $taken = ClassLock::activeNames($class->id);
            $seen = [];
            $errors = [];

            foreach ($data->names as $index => $name) {
                $key = DisplayName::key($name);

                if (isset($taken[$key])) {
                    $errors["names.{$index}"] = __('students.errors.name_taken');
                } elseif (isset($seen[$key])) {
                    $errors["names.{$index}"] = __('students.errors.name_repeated');
                }

                $seen[$key] = true;
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            $active = $class->students()->active()->get(['avatar', 'position']);
            $usage = array_fill_keys(Avatars::keys(), 0);

            foreach ($active as $student) {
                if (isset($usage[$student->avatar])) {
                    $usage[$student->avatar]++;
                }
            }

            $position = (int) $active->max('position');
            $added = new Collection;

            foreach ($data->names as $name) {
                $avatar = $this->leastUsed($usage);
                $usage[$avatar]++;

                $added->push($class->students()->create([
                    'display_name' => $name,
                    'avatar' => $avatar,
                    'position' => ++$position,
                ]));
            }

            return $added;
        });
    }

    /**
     * The first avatar with the lowest count.
     *
     * @param  non-empty-array<string, int>  $usage
     */
    private function leastUsed(array $usage): string
    {
        $fewest = min($usage);

        return (string) array_search($fewest, $usage, true);
    }
}
