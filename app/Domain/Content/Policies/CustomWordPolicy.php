<?php

namespace App\Domain\Content\Policies;

use App\Domain\Content\Models\CustomWord;
use App\Models\User;

/**
 * Teacher isolation, second layer (docs/architecture.md §7): `{word}`
 * already binds only the teacher's own words, and every route with it also
 * passes this policy (`->can(...)`). A teacher word belongs to its teacher
 * alone (ADR 007).
 */
final class CustomWordPolicy
{
    public function update(User $user, CustomWord $word): bool
    {
        return $word->isOwnedBy($user);
    }

    public function delete(User $user, CustomWord $word): bool
    {
        return $word->isOwnedBy($user);
    }
}
