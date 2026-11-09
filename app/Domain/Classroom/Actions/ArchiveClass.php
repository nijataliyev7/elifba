<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Models\SchoolClass;

/**
 * Hides a class from pickers. `RestoreClass` brings it back; `PruneArchived`
 * deletes it, with its students, after `elifba.retention_days`.
 */
final readonly class ArchiveClass
{
    public function handle(SchoolClass $class): SchoolClass
    {
        if ($class->archived_at === null) {
            $class->update(['archived_at' => now()]);
        }

        return $class;
    }
}
