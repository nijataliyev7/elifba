<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Models\SchoolClass;

/**
 * Brings an archived class back, with the students it had.
 */
final readonly class RestoreClass
{
    public function handle(SchoolClass $class): SchoolClass
    {
        if ($class->archived_at !== null) {
            $class->update(['archived_at' => null]);
        }

        return $class;
    }
}
