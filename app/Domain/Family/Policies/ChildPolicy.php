<?php

namespace App\Domain\Family\Policies;

use App\Domain\Family\Models\Child;
use App\Models\User;

/**
 * Isolation, second layer for a parent's children (docs/architecture.md §7
 * "Parents and their children", ADR 011): `{child}` already binds only the
 * account's own children, and every route with it also passes this policy
 * (`->can()`). It asks for ownership only, never for a role (ADR 010).
 */
final class ChildPolicy
{
    public function view(User $user, Child $child): bool
    {
        return $child->isOwnedBy($user);
    }

    public function update(User $user, Child $child): bool
    {
        return $child->isOwnedBy($user);
    }

    public function delete(User $user, Child $child): bool
    {
        return $child->isOwnedBy($user);
    }
}
