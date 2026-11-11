<?php

namespace App\Actions\Roles;

use App\Data\AreaData;
use App\Models\User;

/**
 * Moves an account with both roles to the other area (docs/architecture.md
 * §1 "Accounts and roles", ADR 010 decision 7): `users.area`, which decides
 * where `/start` lands and which navigation the shared and account pages
 * show. `$data` has passed `AreaData::rules()`, so the area is a role the
 * account holds.
 */
final readonly class SwitchArea
{
    public function handle(User $user, AreaData $data): void
    {
        $user->forceFill(['area' => $data->area])->save();
    }
}
