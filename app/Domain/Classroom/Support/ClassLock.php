<?php

namespace App\Domain\Classroom\Support;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;

/**
 * Locks a class row for the rest of the current transaction. Student names
 * are unique among a class's active students under Azerbaijani casing, which
 * no database index can express, so every write that checks a name (or the
 * order) holds this lock first. `FOR UPDATE` on PostgreSQL; on SQLite the
 * transaction itself serializes writers.
 */
final class ClassLock
{
    public static function acquire(string $schoolClassId): SchoolClass
    {
        return SchoolClass::query()->whereKey($schoolClassId)->lockForUpdate()->firstOrFail();
    }

    /**
     * The comparison keys (`DisplayName::key`) of the class's active
     * students, optionally without one of them.
     *
     * @return array<string, true>
     */
    public static function activeNames(string $schoolClassId, ?string $exceptStudentId = null): array
    {
        $names = Student::query()
            ->where('school_class_id', $schoolClassId)
            ->active()
            ->when($exceptStudentId !== null, fn ($query) => $query->whereKeyNot($exceptStudentId))
            ->pluck('display_name');

        $keys = [];

        foreach ($names as $name) {
            $keys[DisplayName::key($name)] = true;
        }

        return $keys;
    }
}
