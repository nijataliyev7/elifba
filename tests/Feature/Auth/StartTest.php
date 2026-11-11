<?php

use App\Enums\Role;
use App\Models\User;

/*
 * `/start` (docs/architecture.md §1 "Accounts and roles", ADR 010 decision
 * 5): where a signed-in account lands, by role. Fortify's `home`, the guest
 * pages' redirect, `/dashboard` and the manifest's start lead here.
 */

it('lands by role', function (string $state, array $attributes, string $home): void {
    $user = User::factory()->{$state}()->create($attributes);

    $this->actingAs($user)->get(route('start'))->assertRedirect(route($home));
})->with([
    'no role yet' => ['withoutRole', [], 'role'],
    'a teacher' => ['configure', [], 'classes.index'],
    'a parent' => ['parent', [], 'children.index'],
    'both, in the teacher area' => ['teacherAndParent', ['area' => Role::Teacher], 'classes.index'],
    'both, in the parent area' => ['teacherAndParent', ['area' => Role::Parent], 'children.index'],
    // One role: the stored area is ignored.
    'a teacher with a stale parent area' => ['configure', ['area' => Role::Parent], 'classes.index'],
    'a parent with no area' => ['parent', ['area' => null], 'children.index'],
]);

it('lands a login on the start', function (): void {
    $parent = User::factory()->parent()->create();

    $this->post(route('login.store'), ['email' => $parent->email, 'password' => 'password'])
        ->assertRedirect(route('start', absolute: false));
    $this->get(route('start'))->assertRedirect(route('children.index'));
});

it('sends a signed-in account away from the guest pages to the start', function (): void {
    $this->actingAs(User::factory()->parent()->create())
        ->get(route('register'))
        ->assertRedirect(route('start'));
});
