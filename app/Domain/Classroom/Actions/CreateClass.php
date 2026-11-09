<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Data\ClassData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Models\User;

/**
 * Creates a class for a teacher. A new class has learned every letter
 * (`learned_letters` null) until the teacher switches some off.
 */
final readonly class CreateClass
{
    public function handle(User $teacher, ClassData $data): SchoolClass
    {
        $class = new SchoolClass($data->toAttributes());
        $class->teacher()->associate($teacher);
        $class->save();

        return $class;
    }
}
