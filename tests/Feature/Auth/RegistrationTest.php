<?php

use App\Models\User;
use Laravel\Fortify\Features;

/*
 * Registration requires the teacher's agreement (docs/architecture.md §7):
 * "I have read the privacy note and use Elifba with my school's permission".
 */

beforeEach(function (): void {
    $this->skipUnlessFortifyHas(Features::registration());
});

/**
 * @return array<string, string>
 */
function registration(array $overrides = []): array
{
    return [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        ...$overrides,
    ];
}

it('renders the registration screen with the privacy checkbox', function (): void {
    $this->get(route('register'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('auth/register'));

    expect(__('auth.register.terms', [], 'az'))->toContain('[')
        ->and(__('auth.register.terms', [], 'en'))->toContain('[');
});

it('registers a teacher who ticked the privacy checkbox and records when', function (): void {
    $this->travelTo('2026-11-09 08:30:00');

    $response = $this->post(route('register.store'), registration());

    $this->assertAuthenticated();
    $response->assertRedirect(route('dashboard', absolute: false));

    expect(User::query()->sole()->terms_accepted_at?->toDateTimeString())->toBe('2026-11-09 08:30:00');
});

it('refuses a registration without the privacy checkbox', function (array $input): void {
    $this->from(route('register'))
        ->post(route('register.store'), $input)
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors(['terms' => __('auth.register.terms_required')]);

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);
})->with([
    'missing' => [fn (): array => array_diff_key(registration(), ['terms' => true])],
    'unticked' => [fn (): array => registration(['terms' => '0'])],
    'empty' => [fn (): array => registration(['terms' => ''])],
]);

it('does not let a profile update set the agreement time', function (): void {
    $user = User::factory()->create(['terms_accepted_at' => null]);

    $this->actingAs($user)->patch(route('profile.update'), [
        'name' => 'Test User',
        'email' => $user->email,
        'terms_accepted_at' => '2020-01-01 00:00:00',
    ]);

    expect($user->fresh()?->terms_accepted_at)->toBeNull();
});
