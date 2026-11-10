<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

it('shows the login page in Azerbaijani by default', function (): void {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('<html lang="az"', false)
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/login')
            ->where('locale', 'az')
            ->where('locales', ['az' => 'Azərbaycanca', 'en' => 'English'])
            ->where('translations', fn ($lines): bool => collect($lines)->get('auth.login.title') === 'Hesabınıza daxil olun'));
});

it('ignores the browser language', function (): void {
    $this->withHeader('Accept-Language', 'en-US,en;q=0.9')
        ->get(route('login'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'az'));
});

it('lets a guest switch to English for the session', function (): void {
    $this->from(route('login'))
        ->put(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('locale', 'en');

    $this->get(route('login'))
        ->assertSee('<html lang="en"', false)
        ->assertInertia(fn (Assert $page) => $page
            ->where('locale', 'en')
            ->where('translations', fn ($lines): bool => collect($lines)->get('auth.login.title') === 'Log in to your account'));
});

it('rejects a language Elifba does not offer', function (): void {
    $this->from(route('login'))
        ->put(route('locale.update'), ['locale' => 'ru'])
        ->assertSessionHasErrors('locale');

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page->where('locale', 'az'));
});

it('saves the choice on the teacher account, which wins over the session', function (): void {
    $user = User::factory()->create(['locale' => null]);

    $this->actingAs($user)
        ->put(route('locale.update'), ['locale' => 'en'])
        ->assertRedirect();

    expect($user->fresh()?->locale)->toBe('en');

    $this->withSession(['locale' => 'az'])
        ->get(route('classes.index'))
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('translates server messages', function (): void {
    $this->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'wrong'])
        ->assertSessionHasErrors(['email' => __('auth.failed', [], 'az')]);

    expect(__('auth.failed', [], 'az'))->toBe('E-poçt ünvanı və ya şifrə yanlışdır.');
});

it('keeps the registration language on the new account', function (): void {
    $this->withSession(['locale' => 'en'])->post(route('register.store'), [
        'name' => 'Aygün Məmmədova',
        'email' => 'aygun@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
    ]);

    expect(User::query()->where('email', 'aygun@example.com')->value('locale'))->toBe('en');
});
