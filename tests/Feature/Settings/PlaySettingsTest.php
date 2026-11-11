<?php

use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\SoundChoices;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * Settings → Oyun (docs/architecture.md §4 "Pairing", §3 "Sounds"): the
 * teacher turns the phone remote off or on (on by default) and chooses the
 * sounds the boards play (the pack's own by default).
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
    $this->put(route('play-settings.sounds.update'), ['miss' => 'bong'])->assertRedirect(route('login'));
});

it('offers every sound of content/sounds.json, with the pack\'s own chosen for a new account', function (): void {
    $this->actingAs($this->teacher)->get(route('play-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            // Only the moments an account chooses: a pack's arrive and leave are its own.
            ->where('sounds.chosen', ['hit' => null, 'miss' => null, 'solved' => null])
            ->where('sounds.options', fn ($options): bool => array_keys(collect($options)->all()) === ['hit', 'miss', 'solved'])
            ->where('sounds.options.miss', array_values(array_map(static fn ($choice): array => [
                'key' => $choice->key,
                'title' => $choice->title->az,
                'url' => SoundChoices::url($choice),
                'every_pack' => true,
            ], SoundChoices::of(Sound::Miss))))
            ->where('sounds.options.hit.0.key', 'pop')
            ->where('sounds.options.hit.0.every_pack', false)
            ->where('sounds.options.hit.1.every_pack', true));
});

it('saves the chosen sounds and goes back to the pack\'s own', function (): void {
    $this->actingAs($this->teacher)->put(route('play-settings.sounds.update'), ['hit' => '', 'miss' => 'pluck-down', 'solved' => 'bells'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('play-settings.edit'));

    expect($this->teacher->refresh()->board_sounds)->toBe(['miss' => 'pluck-down', 'solved' => 'bells'])
        ->and($this->teacher->phone_remote)->toBeTrue();

    $this->get(route('play-settings.edit'))->assertInertia(fn (Assert $page) => $page
        ->where('sounds.chosen', ['hit' => null, 'miss' => 'pluck-down', 'solved' => 'bells']));

    $this->put(route('play-settings.sounds.update'), [])->assertSessionHasNoErrors();

    expect($this->teacher->refresh()->board_sounds)->toBeNull();
});

it('refuses a sound the moment does not list and changes nothing', function (array $body, string $field): void {
    $this->teacher->forceFill(['board_sounds' => ['miss' => 'bong']])->save();

    $this->actingAs($this->teacher)->from(route('play-settings.edit'))
        ->put(route('play-settings.sounds.update'), $body)
        ->assertRedirect(route('play-settings.edit'))
        ->assertSessionHasErrors($field);

    expect($this->teacher->refresh()->board_sounds)->toBe(['miss' => 'bong']);
})->with([
    'an unknown key' => [['miss' => 'boing'], 'miss'],
    'a sound of another moment' => [['hit' => 'bells'], 'hit'],
    'not a string' => [['solved' => ['bells']], 'solved'],
    'a pack\'s own arrive' => [['miss' => 'pluck-down', 'arrive' => 'bells'], 'arrive'],
    'a pack\'s own leave' => [['leave' => 'pop'], 'leave'],
]);

it('lets a parent choose the sounds of the home board too', function (): void {
    $parent = User::factory()->parent()->create();

    $this->actingAs($parent)->put(route('play-settings.sounds.update'), ['miss' => 'pluck-down'])->assertSessionHasNoErrors();

    expect($parent->refresh()->board_sounds)->toBe(['miss' => 'pluck-down']);
});
