<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Models\SchoolClass;

/**
 * Deletes a class for good; its students (and, from A6, its games) go with
 * it through the foreign keys (docs/architecture.md §7).
 */
final readonly class DeleteClass
{
    public function handle(SchoolClass $class): void
    {
        $class->delete();
    }
}
