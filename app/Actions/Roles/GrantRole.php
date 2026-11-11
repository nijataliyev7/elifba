<?php

namespace App\Actions\Roles;

use App\Data\RoleChoiceData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gives an account a role (docs/architecture.md §1 "Accounts and roles", §7
 * "Agreements", ADR 010, ADR 013): the choice right after registration and
 * the second role alike. `$data` has passed `RoleChoiceData::rules()`, so
 * the role's sentence is accepted; its moment is stamped on the account, and
 * the account moves to the area just granted. The only place a role is
 * given. A role the account already holds changes nothing: neither its
 * agreement nor the area. Roles are never removed.
 *
 * Returns whether the role was given now.
 */
final readonly class GrantRole
{
    public function handle(User $user, RoleChoiceData $data): bool
    {
        return DB::transaction(function () use ($user, $data): bool {
            // Two tabs choosing at once: the second waits, then finds the role.
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $user->unsetRelation('roles');

            if ($user->hasRole($data->role)) {
                return false;
            }

            $user->assignRole($data->role);

            // Not mass assignable: only this action sets them.
            $user->forceFill([
                $data->role->agreementColumn() => now(),
                'area' => $data->role,
            ])->save();

            return true;
        });
    }
}
