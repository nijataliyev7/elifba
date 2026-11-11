<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Roles\GrantRole;
use App\Data\RoleChoiceData;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → «Rollar» (docs/architecture.md §1 "Accounts and roles", ADR 010
 * decision 6): the roles the account holds and the one it lacks, which it
 * takes with that role's sentence. A role is never removed.
 */
final class RolesController extends Controller
{
    public function edit(#[CurrentUser] User $user): Response
    {
        $held = $user->roleList();

        return Inertia::render('settings/roles', [
            'held' => array_map(static fn (Role $role): string => $role->value, $held),
            'available' => array_values(array_map(
                static fn (Role $role): string => $role->value,
                array_filter(Role::cases(), static fn (Role $role): bool => ! in_array($role, $held, true)),
            )),
        ]);
    }

    /**
     * Takes the role and opens its area, where `GrantRole` moved the account;
     * a role it already holds leaves everything as it was.
     */
    public function store(Request $request, #[CurrentUser] User $user, GrantRole $grantRole): RedirectResponse
    {
        $data = RoleChoiceData::validate($request->all());

        if (! $grantRole->handle($user, $data)) {
            return to_route('roles.edit');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('roles.settings.added')]);

        return to_route($data->role->homeRoute());
    }
}
