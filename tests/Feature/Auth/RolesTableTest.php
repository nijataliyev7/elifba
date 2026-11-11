<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/*
 * spatie/laravel-permission, roles only (docs/architecture.md §1 "Accounts
 * and roles", ADR 010 decisions 1–2): the `roles` table is the enum, no
 * permission exists, the package adds no Gate hook, and the rows name the
 * account by its morph alias, never by a class name.
 */

it('holds exactly the roles of the enum', function (): void {
    $roles = DB::table('roles')->orderBy('id')->get(['name', 'guard_name'])
        ->map(static fn (object $row): array => (array) $row)->all();

    expect($roles)->toBe(array_map(
        static fn (Role $role): array => ['name' => $role->value, 'guard_name' => 'web'],
        Role::cases(),
    ));
});

it('defines no permission and keeps the package out of the Gate', function (): void {
    User::factory()->teacherAndParent()->create();

    expect(DB::table('permissions')->count())->toBe(0)
        ->and(DB::table('role_has_permissions')->count())->toBe(0)
        ->and(DB::table('model_has_permissions')->count())->toBe(0)
        ->and(config('permission.register_permission_check_method'))->toBeFalse()
        ->and(config('permission.teams'))->toBeFalse()
        ->and(Gate::getFacadeRoot()?->has('teacher'))->toBeFalse();
});

it('names the account by its morph alias', function (): void {
    $user = User::factory()->create();

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->pluck('model_type')->all())->toBe(['user']);
});

it('keeps one role row per role an account holds', function (): void {
    $user = User::factory()->create();

    $user->assignRole(Role::Teacher);

    expect(DB::table('model_has_roles')->where('model_id', $user->id)->count())->toBe(1)
        ->and($user->refresh()->roleList())->toBe([Role::Teacher]);
});
