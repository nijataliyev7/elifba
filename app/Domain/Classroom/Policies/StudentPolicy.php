<?php

namespace App\Domain\Classroom\Policies;

use App\Domain\Classroom\Models\Student;
use App\Models\User;

/**
 * Teacher isolation, second layer for students (docs/architecture.md §7):
 * a student belongs to the teacher who owns their class.
 */
final class StudentPolicy
{
    public function update(User $user, Student $student): bool
    {
        return $student->isOwnedBy($user);
    }

    public function delete(User $user, Student $student): bool
    {
        return $student->isOwnedBy($user);
    }
}
