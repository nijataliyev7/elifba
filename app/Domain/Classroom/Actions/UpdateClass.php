<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Data\ClassData;
use App\Domain\Classroom\Models\SchoolClass;

/**
 * Renames a class or changes its grade.
 */
final readonly class UpdateClass
{
    public function handle(SchoolClass $class, ClassData $data): SchoolClass
    {
        $class->update($data->toAttributes());

        return $class;
    }
}
