<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The migration that brings roles (docs/architecture.md §1 "Accounts and
 * roles", ADR 010 decision 8): every account that existed before is a
 * teacher in the teacher area, whose teacher agreement is the moment it
 * accepted the school sentence at registration.
 */

it('makes every existing account a teacher with its agreement', function (): void {
    $migration = require database_path('migrations/2026_11_17_000002_add_roles_to_users.php');
    $migration->down();

    expect(Schema::hasColumns('users', ['area', 'teacher_agreed_at', 'parent_agreed_at']))->toBeFalse()
        ->and(DB::table('roles')->count())->toBe(0);

    $account = static function (string $email, ?string $acceptedAt): int {
        return DB::table('users')->insertGetId([
            'name' => 'Müəllim',
            'email' => $email,
            'password' => 'not-a-real-hash',
            'email_verified_at' => '2026-11-01 08:00:00',
            'terms_accepted_at' => $acceptedAt,
            'created_at' => '2026-11-01 08:00:00',
            'updated_at' => '2026-11-01 08:00:00',
        ]);
    };

    $agreed = $account('aygun@example.com', '2026-11-09 08:30:00');
    // Registered before the agreement existed.
    $older = $account('leyla@example.com', null);

    $migration->up();

    foreach ([$agreed => '2026-11-09 08:30:00', $older => null] as $id => $acceptedAt) {
        $user = User::query()->findOrFail($id);

        expect($user->roleList())->toBe([Role::Teacher])
            ->and($user->area)->toBe(Role::Teacher)
            ->and($user->area())->toBe(Role::Teacher)
            ->and($user->teacher_agreed_at?->toDateTimeString())->toBe($acceptedAt)
            ->and($user->parent_agreed_at)->toBeNull();
    }

    expect(DB::table('roles')->orderBy('id')->pluck('name')->all())->toBe(['teacher', 'parent'])
        ->and(DB::table('model_has_roles')->count())->toBe(2)
        ->and(DB::table('model_has_roles')->distinct()->pluck('model_type')->all())->toBe(['user']);

    // They go on as teachers.
    $this->actingAs(User::query()->findOrFail($agreed))->get(route('start'))->assertRedirect(route('classes.index'));
});
