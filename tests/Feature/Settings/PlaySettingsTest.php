<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Settings → Oyun (docs/architecture.md §4 "Pairing"): the teacher turns the
 * phone remote off or on; it is on by default.
 */

beforeEach(function (): void {
    $this->teacher = User::factory()->create();
});

it('shows the phone remote on for a new teacher', function (): void {
    expect($this->teacher->refresh()->phone_remote)->toBeTrue();

    $this->actingAs($this->teacher)->get(route('play-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/play')
            ->where('phoneRemote', true));
});

it('turns the phone remote off and on again', function (mixed $off, mixed $on): void {
    $this->actingAs($this->teacher)->put(route('play-settings.update'), ['phone_remote' => $off])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('play-settings.edit'));

    expect($this->teacher->refresh()->phone_remote)->toBeFalse();

    $this->get(route('play-settings.edit'))->assertInertia(fn (Assert $page) => $page->where('phoneRemote', false));

    $this->put(route('play-settings.update'), ['phone_remote' => $on])->assertSessionHasNoErrors();

    expect($this->teacher->refresh()->phone_remote)->toBeTrue();
})->with([
    'form fields' => ['0', '1'],
    'JSON booleans' => [false, true],
]);

it('refuses anything but a boolean and changes nothing', function (array $body): void {
    $this->actingAs($this->teacher)->from(route('play-settings.edit'))
        ->put(route('play-settings.update'), $body)
        ->assertRedirect(route('play-settings.edit'))
        ->assertSessionHasErrors('phone_remote');

    expect($this->teacher->refresh()->phone_remote)->toBeTrue();
})->with([
    'missing' => [[]],
    'empty' => [['phone_remote' => '']],
    'a word' => [['phone_remote' => 'off']],
    'a number' => [['phone_remote' => '2']],
]);

it('changes only the teacher\'s own setting', function (): void {
    $other = User::factory()->create();

    $this->actingAs($this->teacher)->put(route('play-settings.update'), ['phone_remote' => '0'])->assertSessionHasNoErrors();

    expect($other->refresh()->phone_remote)->toBeTrue();
});

it('sends a guest to the login page', function (): void {
    $this->get(route('play-settings.edit'))->assertRedirect(route('login'));
    $this->put(route('play-settings.update'), ['phone_remote' => '0'])->assertRedirect(route('login'));
});
