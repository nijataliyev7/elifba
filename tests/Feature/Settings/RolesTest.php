<?php

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Settings → «Rollar» and the area switch (docs/architecture.md §1
 * "Accounts and roles", ADR 010 decisions 6–7): an account takes the role it
 * lacks with that role's sentence, moves between the two areas when it has
 * both, and never loses a role.
 */

it('shows the roles the account holds and the one it can add', function (string $state, array $held, array $available): void {
    $this->actingAs(User::factory()->{$state}()->create())
        ->get(route('roles.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/roles')
            ->where('held', $held)
            ->where('available', $available));
})->with([
    'a teacher' => ['configure', ['teacher'], ['parent']],
    'a parent' => ['parent', ['parent'], ['teacher']],
    'both' => ['teacherAndParent', ['teacher', 'parent'], []],
]);

it('adds the second role with its agreement', function (string $state, Role $role, Role $kept): void {
    $this->travelTo('2026-11-11 09:00:00');
    $user = User::factory()->{$state}()->create();
    $keptAt = $user->getAttribute($kept->agreementColumn());
    $this->travelTo('2026-12-01 18:30:00');

    $this->actingAs($user)
        ->post(route('roles.store'), ['role' => $role->value, 'agreement' => '1'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route($role->homeRoute()));

    $user->refresh();
    expect($user->roleList())->toEqualCanonicalizing([Role::Teacher, Role::Parent])
        ->and($user->getAttribute($role->agreementColumn())?->toDateTimeString())->toBe('2026-12-01 18:30:00')
        ->and($user->getAttribute($kept->agreementColumn())?->toDateTimeString())->toBe($keptAt?->toDateTimeString())
        // Into the area just granted.
        ->and($user->area)->toBe($role)
        ->and($user->area())->toBe($role);

    $this->get(route($role->homeRoute()))->assertOk();
    $this->get(route($kept->homeRoute()))->assertOk();
})->with([
    'a teacher becomes a parent too' => ['configure', Role::Parent, Role::Teacher],
    'a parent becomes a teacher too' => ['parent', Role::Teacher, Role::Parent],
]);

it('refuses the second role without its agreement', function (): void {
    $teacher = User::factory()->create();

    $this->actingAs($teacher)
        ->from(route('roles.edit'))
        ->post(route('roles.store'), ['role' => 'parent'])
        ->assertRedirect(route('roles.edit'))
        ->assertSessionHasErrors(['agreement' => __('roles.errors.agreement')]);

    $teacher->refresh();
    expect($teacher->roleList())->toBe([Role::Teacher])
        ->and($teacher->parent_agreed_at)->toBeNull()
        ->and($teacher->area)->toBe(Role::Teacher);
});

it('switches the area', function (): void {
    $user = User::factory()->teacherAndParent()->create(['area' => Role::Teacher]);

    $this->actingAs($user)
        ->put(route('area.update'), ['area' => 'parent'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('children.index'));

    expect($user->refresh()->area)->toBe(Role::Parent);
    $this->get(route('start'))->assertRedirect(route('children.index'));
    $this->get(route('profile.edit'))->assertInertia(fn (Assert $page) => $page->where('auth.area', 'parent'));

    $this->put(route('area.update'), ['area' => 'teacher'])->assertRedirect(route('classes.index'));
    expect($user->refresh()->area)->toBe(Role::Teacher);
});

it('refuses an area the account has no role for', function (array $input): void {
    $teacher = User::factory()->create();

    $this->actingAs($teacher)
        ->from(route('classes.index'))
        ->put(route('area.update'), $input)
        ->assertRedirect(route('classes.index'))
        ->assertSessionHasErrors('area');

    expect($teacher->refresh()->area)->toBe(Role::Teacher)
        ->and($teacher->roleList())->toBe([Role::Teacher]);
    $this->get(route('start'))->assertRedirect(route('classes.index'));
})->with([
    'the role it lacks' => [['area' => 'parent']],
    'no role at all' => [['area' => 'admin']],
    'nothing' => [[]],
]);

it('gives an account without a role no area to switch to', function (): void {
    $user = User::factory()->withoutRole()->create();

    $this->actingAs($user)->put(route('area.update'), ['area' => 'teacher'])->assertSessionHasErrors('area');

    expect($user->refresh()->area)->toBeNull();
});

it('never removes a role', function (): void {
    $this->travelTo('2026-11-11 09:00:00');
    $user = User::factory()->teacherAndParent()->create(['area' => Role::Teacher]);
    $this->travelTo('2026-12-01 09:00:00');

    // Taking a role it holds changes nothing: neither its agreement nor the area.
    $this->actingAs($user)
        ->post(route('roles.store'), ['role' => 'parent', 'agreement' => '1'])
        ->assertRedirect(route('roles.edit'));

    $user->refresh();
    expect($user->roleList())->toBe([Role::Teacher, Role::Parent])
        ->and($user->parent_agreed_at?->toDateTimeString())->toBe('2026-11-11 09:00:00')
        ->and($user->area)->toBe(Role::Teacher);

    // No route takes a role away: the role pages only show and give.
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (in_array($route->uri(), ['role', 'settings/roles'], true)) {
            expect(array_values(array_diff($route->methods(), ['GET', 'HEAD', 'POST'])))->toBe([], $route->uri());
        }
    }

    $this->delete('/settings/roles', ['role' => 'parent'])->assertStatus(405);
    $this->delete('/role', ['role' => 'parent'])->assertStatus(405);
    expect($user->refresh()->roleList())->toBe([Role::Teacher, Role::Parent]);
});
