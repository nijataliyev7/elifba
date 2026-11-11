<?php

namespace App\Domain\Family\Support;

use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Content\Support\TeacherLock;
use App\Domain\Family\Models\Child;

/**
 * Every write to a parent's list of children holds the lock on the parent's
 * `users` row first — the lock a teacher's words take (`TeacherLock`) — so
 * the name check and the `elifba.children.max` cap (docs/architecture.md §1
 * `children`) see every committed child and two requests cannot pass them
 * at once.
 */
final class FamilyLock
{
    public static function acquire(int $parentId): void
    {
        TeacherLock::acquire($parentId);
    }

    /**
     * The parent's children's names as `DisplayName::key()` (Azerbaijani
     * casing), optionally without one child (the one being edited).
     *
     * @return array<string, true>
     */
    public static function names(int $parentId, ?string $exceptChildId = null): array
    {
        $names = [];

        $children = Child::query()
            ->where('user_id', $parentId)
            ->when($exceptChildId !== null, static fn ($query) => $query->whereKeyNot($exceptChildId))
            ->pluck('display_name');

        foreach ($children as $name) {
            $names[DisplayName::key((string) $name)] = true;
        }

        return $names;
    }
}
