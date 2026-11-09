<?php

namespace App\Domain\Classroom\Policies;

use App\Domain\Classroom\Models\SchoolClass;
use App\Models\User;

/**
 * Teacher isolation, second layer (docs/architecture.md §7): `{class}` already
 * binds only the teacher's own classes, and every route with it also passes
 * this policy (`->can(...)`), so one forgotten layer still leaks nothing.
 */
final class SchoolClassPolicy
{
    public function view(User $user, SchoolClass $class): bool
    {
        return $class->isOwnedBy($user);
    }

    public function update(User $user, SchoolClass $class): bool
    {
        return $class->isOwnedBy($user);
    }

    public function delete(User $user, SchoolClass $class): bool
    {
        return $class->isOwnedBy($user);
    }
}
