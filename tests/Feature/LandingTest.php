<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * There is no dashboard (docs/frontend.md §1): a signed-in teacher lands on
 * the classes, and the starter kit's /dashboard is only a redirect for old
 * bookmarks.
 */

it('sends an old /dashboard bookmark on to the classes', function (): void {
    $this->get('/dashboard')->assertStatus(302)->assertRedirect('/classes');

    $this->actingAs(User::factory()->create())
        ->get('/dashboard')
        ->assertStatus(302)
        ->assertRedirect('/classes');
});

it('has no dashboard route or page', function (): void {
    expect(Route::has('dashboard'))->toBeFalse()
        ->and(config('fortify.home'))->toBe('/classes');
});

it('lands a teacher on the classes', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('classes.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('classes/index'));
});

it('sends a signed-in teacher away from the guest pages to the classes', function (): void {
    $this->actingAs(User::factory()->create())
        ->get(route('login'))
        ->assertRedirect(route('classes.index'));
});

it('sends a guest from the classes to the login page', function (): void {
    $this->get(route('classes.index'))->assertRedirect(route('login'));
});
