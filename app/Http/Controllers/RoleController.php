<?php

namespace App\Http\Controllers;

use App\Actions\Roles\GrantRole;
use App\Data\RoleChoiceData;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The role choice right after registration (docs/architecture.md §1
 * "Accounts and roles", ADR 010 decision 4): «Müəllim» or «Valideyn», each
 * with its sentence. `auth` only, so it comes before e-mail confirmation.
 * An account that already has a role is sent on to `/start`; the second
 * role is added in Settings → «Rollar».
 */
final class RoleController extends Controller
{
    public function create(#[CurrentUser] User $user): Response|RedirectResponse
    {
        if ($user->roleList() !== []) {
            return to_route('start');
        }

        return Inertia::render('auth/choose-role', [
            'roles' => array_map(static fn (Role $role): string => $role->value, Role::cases()),
        ]);
    }

    public function store(Request $request, #[CurrentUser] User $user, GrantRole $grantRole): RedirectResponse
    {
        if ($user->roleList() !== []) {
            return to_route('start');
        }

        $grantRole->handle($user, RoleChoiceData::validate($request->all()));

        return to_route('start');
    }
}
