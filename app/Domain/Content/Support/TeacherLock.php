<?php

namespace App\Domain\Content\Support;

use App\Models\User;

/**
 * Locks an account's `users` row for the rest of the current transaction.
 * Every write to a teacher's words holds it first, so the duplicate check
 * and the count cap (docs/architecture.md §3 "Teacher words") see every
 * committed word and two requests cannot pass them at once; a parent's
 * children take the same lock (`Family\Support\FamilyLock`). `FOR UPDATE`
 * on PostgreSQL; on SQLite the transaction itself serializes writers.
 */
final class TeacherLock
{
    public static function acquire(int $teacherId): void
    {
        User::query()->whereKey($teacherId)->lockForUpdate()->firstOrFail();
    }
}
