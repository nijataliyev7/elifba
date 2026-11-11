<?php

use App\Models\User;
use App\Support\GoogleSignIn;
use Illuminate\Auth\SessionGuard;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Features;
use Tests\Support\FakeGoogle;
use Tests\TestCase;

/*
 * Google sign-in for teachers (docs/architecture.md §7 "Google sign-in",
 * ADR 008). Google itself is FakeGoogle: Socialite's own state and PKCE
 * checks run, and nothing leaves the machine.
 */

const GOOGLE_SUB = '109876543210987654321';

beforeEach(function (): void {
    $this->google = FakeGoogle::install();
});

/**
 * Starts a round trip at $start and comes back from Google with a code and
 * the state the redirect sent.
 *
 * @param  array<string, string>  $answer  more of Google's answer (`error`, `state`)
 */
function throughGoogle(TestCase $test, string $start = 'google.redirect', array $answer = []): TestResponse
{
    $location = (string) $test->get(route($start))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $test->get(route('google.callback', [
        'code' => '4/test-code',
        'state' => $query['state'],
        ...$answer,
    ]));
}

it('sends the teacher to Google\'s account chooser with the three scopes, state and PKCE', function (): void {
    $location = (string) $this->get(route('google.redirect'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(parse_url($location, PHP_URL_SCHEME).'://'.parse_url($location, PHP_URL_HOST))->toBe('https://accounts.google.com')
        ->and($query)->toMatchArray([
            'client_id' => FakeGoogle::CLIENT_ID,
            'redirect_uri' => url('/auth/google/callback'),
            'scope' => 'openid email profile',
            'response_type' => 'code',
            'prompt' => 'select_account',
            'code_challenge_method' => 'S256',
        ])
        ->and($query['state'])->toHaveLength(40)
        ->and($query['code_challenge'])->not->toBeEmpty()
        ->and(session('state'))->toBe($query['state'])
        ->and(session(GoogleSignIn::INTENT))->toBe(['for' => 'login']);
});

it('comes back to the host the teacher used', function (): void {
    $location = (string) $this->get('http://school.example/auth/google/redirect')->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['redirect_uri'])->toBe('http://school.example/auth/google/callback');
});

it('signs in the teacher linked to the Google account, without "remember me"', function (): void {
    $teacher = User::factory()->create(['google_id' => GOOGLE_SUB, 'email' => 'changed@example.com']);
    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);

    throughGoogle($this)
        ->assertRedirect(route('start', absolute: false))
        ->assertCookieMissing($guard->getRecallerName());

    $this->assertAuthenticatedAs($teacher);
    expect($this->google->tokenRequest())
        ->toMatchArray(['code' => '4/test-code', 'redirect_uri' => url('/auth/google/callback')])
        ->toHaveKey('code_verifier');
});

it('links a teacher whose address is confirmed, case-insensitively, and keeps the password', function (): void {
    $teacher = User::factory()->create(['email' => 'Aygun@Gmail.com']);

    throughGoogle($this)->assertRedirect(route('start', absolute: false));

    $this->assertAuthenticatedAs($teacher);
    $teacher->refresh();
    expect($teacher->google_id)->toBe(GOOGLE_SUB)
        ->and(Hash::check('password', (string) $teacher->password))->toBeTrue()
        ->and($teacher->email)->toBe('Aygun@Gmail.com')
        ->and($teacher->name)->not->toBe('Aygün Məmmədova');
});

it('takes over an unconfirmed account and drops every credential someone else may have set', function (): void {
    // Sessions in the database, as in production, so other devices can be signed out.
    config(['session.driver' => 'database']);
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[] = $message->message.' '.json_encode($message->context);
    });

    $teacher = User::factory()->unverified()->withTwoFactor()->create([
        'email' => 'aygun@gmail.com',
        'remember_token' => 'someone-elses-token',
    ]);
    $other = User::factory()->create();
    DB::table('sessions')->insert([
        ['id' => 'someone-elses-session', 'user_id' => $teacher->id, 'payload' => '', 'last_activity' => time()],
        ['id' => 'another-teachers-session', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()],
    ]);

    // No two-factor challenge: the second factor was someone else's too.
    throughGoogle($this)->assertRedirect(route('start', absolute: false));

    $this->assertAuthenticatedAs($teacher);
    $teacher->refresh();
    expect($teacher->email_verified_at)->not->toBeNull()
        ->and($teacher->google_id)->toBe(GOOGLE_SUB)
        ->and($teacher->password)->toBeNull()
        ->and($teacher->two_factor_secret)->toBeNull()
        ->and($teacher->two_factor_recovery_codes)->toBeNull()
        ->and($teacher->two_factor_confirmed_at)->toBeNull()
        ->and($teacher->remember_token)->not->toBe('someone-elses-token')
        ->and(implode("\n", $logged))->not->toContain('aygun@gmail.com')->not->toContain('Aygün');
    $this->assertDatabaseMissing('sessions', ['id' => 'someone-elses-session']);
    $this->assertDatabaseHas('sessions', ['id' => 'another-teachers-session']);
});

it('refuses an address Google has not verified', function (array $claims): void {
    $teacher = User::factory()->create(['email' => 'aygun@gmail.com']);
    $this->google->claims = [...$this->google->claims, ...$claims];
    unset($this->google->claims['missing']);

    throughGoogle($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => __('auth.google.unverified')]);

    $this->assertGuest();
    expect($teacher->refresh()->google_id)->toBeNull()
        ->and(session(GoogleSignIn::PENDING))->toBeNull();
})->with([
    'false' => [['email_verified' => false]],
    'a string' => [['email_verified' => 'true']],
    'missing' => [['email_verified' => null, 'missing' => true]],
]);

it('trusts only addresses Google vouches for: Gmail, or a Google Workspace domain', function (): void {
    $teacher = User::factory()->create(['email' => 'aygun@school.az']);

    // A Google account made on an address that may have changed hands since.
    $this->google->answers(['email' => 'aygun@school.az']);
    throughGoogle($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => __('auth.google.not_authoritative')]);
    $this->assertGuest();
    expect($teacher->refresh()->google_id)->toBeNull();

    // The school's own Google Workspace.
    $this->google->answers(['hd' => 'school.az']);
    throughGoogle($this)->assertRedirect(route('start', absolute: false));
    $this->assertAuthenticatedAs($teacher);
    expect($teacher->refresh()->google_id)->toBe(GOOGLE_SUB);
});

it('never takes over an account whose teacher changed to this address and has not confirmed it', function (): void {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $teacher = User::factory()->withTwoFactor()->create(['email' => 'old@gmail.com']);
    $this->actingAs($teacher)->patch(route('profile.update'), ['name' => $teacher->name, 'email' => 'aygun@gmail.com'])
        ->assertSessionHasNoErrors();
    Auth::logout();

    throughGoogle($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => __('auth.google.unconfirmed_account')]);

    $this->assertGuest();
    $teacher->refresh();
    expect($teacher->google_id)->toBeNull()
        ->and($teacher->email_verified_at)->toBeNull()
        ->and(Hash::check('password', (string) $teacher->password))->toBeTrue()
        ->and($teacher->two_factor_confirmed_at)->not->toBeNull();
});

it('unlinks Google when the teacher changes the address, and asks a teacher without a password to set one first', function (): void {
    $linked = User::factory()->create(['google_id' => GOOGLE_SUB]);

    $this->actingAs($linked)->patch(route('profile.update'), ['name' => $linked->name, 'email' => 'someone-else@school.az'])
        ->assertSessionHasNoErrors();
    $linked->refresh();
    expect($linked->google_id)->toBeNull()
        ->and($linked->email_verified_at)->toBeNull()
        ->and($linked->email_changed_at)->not->toBeNull();

    // The Google account no longer reaches it.
    Auth::logout();
    throughGoogle($this)->assertRedirect(route('google.register'));
    $this->assertGuest();

    $passwordless = User::factory()->create(['google_id' => 'another-sub', 'password' => null]);
    $this->actingAs($passwordless)->patch(route('profile.update'), ['name' => 'Yeni ad', 'email' => 'new@gmail.com'])
        ->assertSessionHasErrors(['email' => __('settings.profile.set_password_first')]);
    expect($passwordless->refresh()->email)->not->toBe('new@gmail.com')
        ->and($passwordless->google_id)->toBe('another-sub');

    // Only the address is held back: the name changes as before.
    $this->patch(route('profile.update'), ['name' => 'Yeni ad', 'email' => $passwordless->email])
        ->assertSessionHasNoErrors();
    expect($passwordless->refresh()->name)->toBe('Yeni ad');
});

it('refuses an address whose teacher is linked to another Google account', function (): void {
    $teacher = User::factory()->create(['email' => 'aygun@gmail.com', 'google_id' => 'another-google-account']);

    throughGoogle($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => __('auth.google.other_account')]);

    $this->assertGuest();
    expect($teacher->refresh()->google_id)->toBe('another-google-account');
});

it('sends a teacher with two-factor authentication to the challenge, never straight in', function (): void {
    Features::twoFactorAuthentication(['confirm' => true, 'confirmPassword' => true]);
    $teacher = User::factory()->withTwoFactor()->create(['google_id' => GOOGLE_SUB]);

    throughGoogle($this)
        ->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $teacher->id)
        ->assertSessionHas('login.remember', false);

    $this->assertGuest();
    $this->get(route('two-factor.login'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('auth/two-factor-challenge'));
    $this->get(route('classes.index'))->assertRedirect(route('login'));
});

it('asks a new teacher for the privacy-note agreement before creating the account', function (): void {
    Notification::fake();

    throughGoogle($this)->assertRedirect(route('google.register'));

    $this->assertGuest();
    expect(User::query()->count())->toBe(0);

    $this->get(route('google.register'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/google-register')
            ->where('email', 'aygun@gmail.com')
            ->where('name', 'Aygün Məmmədova'));

    $this->post(route('google.register.store'), ['name' => 'Aygün Məmmədova'])
        ->assertSessionHasErrors(['terms' => __('auth.register.terms_required')]);
    expect(User::query()->count())->toBe(0);
    $this->assertGuest();

    $this->post(route('google.register.store'), ['name' => 'Aygün müəllimə', 'terms' => '1'])
        ->assertRedirect(route('start', absolute: false));

    $teacher = User::query()->sole();
    $this->assertAuthenticatedAs($teacher);
    expect($teacher->name)->toBe('Aygün müəllimə')
        ->and($teacher->email)->toBe('aygun@gmail.com')
        ->and($teacher->google_id)->toBe(GOOGLE_SUB)
        ->and($teacher->password)->toBeNull()
        ->and($teacher->email_verified_at)->not->toBeNull()
        ->and($teacher->terms_accepted_at)->not->toBeNull()
        ->and($teacher->locale)->toBe('az')
        ->and($teacher->roleList())->toBe([])
        ->and(session(GoogleSignIn::PENDING))->toBeNull();
    Notification::assertNothingSent();

    // The next time, Google signs her straight in.
    Auth::logout();
    throughGoogle($this)->assertRedirect(route('start', absolute: false));
    $this->assertAuthenticatedAs($teacher);
});

it('sends the completion page to login without a Google profile or after 15 minutes', function (): void {
    $this->get(route('google.register'))->assertRedirect(route('login'));
    $this->post(route('google.register.store'), ['name' => 'Aygün', 'terms' => '1'])
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => __('auth.google.expired')]);

    throughGoogle($this)->assertRedirect(route('google.register'));
    $this->travel(GoogleSignIn::PENDING_MINUTES + 1)->minutes();

    $this->get(route('google.register'))->assertRedirect(route('login'));
    $this->post(route('google.register.store'), ['name' => 'Aygün', 'terms' => '1'])->assertRedirect(route('login'));
    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

it('forgets Google\'s name and address when the teacher cancels the completion page', function (): void {
    throughGoogle($this)->assertRedirect(route('google.register'));
    expect(session(GoogleSignIn::PENDING))->toBeArray();

    $this->delete(route('google.register.destroy'))->assertRedirect(route('login'));

    expect(session(GoogleSignIn::PENDING))->toBeNull();
    $this->get(route('google.register'))->assertRedirect(route('login'));
    expect(User::query()->count())->toBe(0);
});

it('fails cleanly when the round trip does not hold', function (Closure $break, string $reason): void {
    $teacher = User::factory()->create(['google_id' => GOOGLE_SUB]);

    $break($this)
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['google' => __($reason)]);

    $this->assertGuest();
    expect($teacher->refresh()->google_id)->toBe(GOOGLE_SUB);
})->with([
    'a forged state' => [fn (TestCase $test) => throughGoogle($test, answer: ['state' => str_repeat('x', 40)]), 'auth.google.failed'],
    'no round trip started' => [fn (TestCase $test) => $test->get(route('google.callback', ['code' => '4/test-code', 'state' => 'abc'])), 'auth.google.failed'],
    'a code Google refuses' => [function (TestCase $test) {
        $test->google->rejectsCode = true;

        return throughGoogle($test);
    }, 'auth.google.failed'],
    'Google down' => [function (TestCase $test) {
        $test->google->down = true;

        return throughGoogle($test);
    }, 'auth.google.failed'],
    'the teacher said no' => [fn (TestCase $test) => throughGoogle($test, answer: ['error' => 'access_denied', 'code' => '']), 'auth.google.cancelled'],
    'a replayed answer' => [function (TestCase $test) {
        $location = (string) $test->get(route('google.redirect'))->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $answer = route('google.callback', ['code' => '4/test-code', 'state' => $query['state']]);
        $test->get($answer);
        Auth::logout();

        return $test->get($answer);
    }, 'auth.google.failed'],
]);

it('confirms the password of a teacher linked to Google and returns to the page that asked', function (): void {
    $teacher = User::factory()->create(['google_id' => GOOGLE_SUB, 'password' => null]);

    $this->actingAs($teacher)->get(route('security.edit'))->assertRedirect(route('password.confirm'));
    $this->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/confirm-password')
            ->where('hasPassword', false)
            ->where('canConfirmWithGoogle', true));

    throughGoogle($this, 'google.confirm')->assertRedirect(route('security.edit'));

    expect(session('auth.password_confirmed_at'))->toBeInt();
    $this->get(route('security.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('hasPassword', false));
});

it('does not confirm with another Google account', function (): void {
    $teacher = User::factory()->create(['google_id' => 'her-own-google-account', 'password' => null]);

    $this->actingAs($teacher);
    throughGoogle($this, 'google.confirm')
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors(['google' => __('auth.google.wrong_account')]);

    expect(session('auth.password_confirmed_at'))->toBeNull();
});

it('says a confirmation, not a sign-in, failed', function (array $answer, string $reason): void {
    $this->actingAs(User::factory()->create(['google_id' => GOOGLE_SUB, 'password' => null]));
    $this->google->rejectsCode = true;

    throughGoogle($this, 'google.confirm', $answer)
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors(['google' => __($reason)]);

    expect(session('auth.password_confirmed_at'))->toBeNull();
    $this->assertAuthenticated();
})->with([
    'the teacher said no' => [['error' => 'access_denied', 'code' => ''], 'auth.google.confirm_cancelled'],
    'a code Google refuses' => [[], 'auth.google.confirm_failed'],
]);

it('keeps the password as the proof for a teacher who has one', function (): void {
    $teacher = User::factory()->create(['google_id' => GOOGLE_SUB]);

    $this->actingAs($teacher)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page->where('hasPassword', true)->where('canConfirmWithGoogle', false));
    $this->get(route('google.confirm'))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasNoErrors();
    expect(session(GoogleSignIn::INTENT))->toBeNull()
        ->and($this->google->requests)->toBe([]);
});

it('offers no Google confirmation to a teacher not linked to Google', function (): void {
    $teacher = User::factory()->create();

    $this->actingAs($teacher)->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page->where('hasPassword', true)->where('canConfirmWithGoogle', false));
    $this->get(route('google.confirm'))
        ->assertRedirect(route('password.confirm'))
        ->assertSessionHasErrors(['google' => __('auth.google.not_linked')]);
});

it('lets a teacher without a password set one once confirmed, with no current password', function (): void {
    $teacher = User::factory()->create(['google_id' => GOOGLE_SUB, 'password' => null]);
    $password = ['password' => 'new-password', 'password_confirmation' => 'new-password'];

    $this->actingAs($teacher)->from(route('security.edit'))->put(route('user-password.update'), $password)
        ->assertSessionHasErrors(['password' => __('auth.google.confirm_first')]);
    expect($teacher->refresh()->password)->toBeNull();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->from(route('security.edit'))
        ->put(route('user-password.update'), $password)
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('security.edit'));
    expect(Hash::check('new-password', (string) $teacher->refresh()->password))->toBeTrue();

    // From now on the current password is asked for, as for everyone.
    $this->put(route('user-password.update'), ['password' => 'newer-password', 'password_confirmation' => 'newer-password'])
        ->assertSessionHasErrors('current_password');
});

it('deletes the account of a teacher without a password after a fresh confirmation', function (): void {
    $teacher = User::factory()->create(['google_id' => GOOGLE_SUB, 'password' => null]);

    $this->actingAs($teacher)->get(route('profile.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('hasPassword', false)
            ->where('passwordConfirmed', false)
            ->where('canConfirmWithGoogle', true));

    $this->delete(route('profile.destroy'))
        ->assertSessionHasErrors(['password' => __('auth.google.confirm_first')]);
    $this->withSession(['auth.password_confirmed_at' => time() - config('auth.password_timeout') - 1])
        ->delete(route('profile.destroy'))
        ->assertSessionHasErrors('password');
    expect($teacher->fresh())->not->toBeNull();

    $this->withSession(['auth.password_confirmed_at' => time()])
        ->delete(route('profile.destroy'))
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');
    expect($teacher->fresh())->toBeNull();
    $this->assertGuest();
});

it('turns the password login away for a teacher without a password', function (): void {
    $teacher = User::factory()->create(['google_id' => GOOGLE_SUB, 'password' => null]);

    $this->post(route('login.store'), ['email' => $teacher->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.failed')]);

    $this->assertGuest();
});

it('offers Google on the login, register and privacy pages when configured', function (): void {
    foreach (['login', 'register', 'privacy'] as $page) {
        $this->get(route($page))->assertInertia(fn (Assert $inertia) => $inertia->where('googleSignIn', true));
    }
});

it('is not there unless both halves of the OAuth client are set', function (string $missing): void {
    config(["services.google.{$missing}" => '']);

    $this->get(route('google.redirect'))->assertNotFound();
    $this->get(route('google.callback', ['code' => '4/test-code', 'state' => 'abc']))->assertNotFound();
    $this->get(route('google.register'))->assertNotFound();
    $this->post(route('google.register.store'))->assertNotFound();
    $this->delete(route('google.register.destroy'))->assertNotFound();

    foreach (['login', 'register', 'privacy'] as $page) {
        $this->get(route($page))->assertInertia(fn (Assert $inertia) => $inertia->where('googleSignIn', false));
    }

    $this->actingAs(User::factory()->create(['google_id' => GOOGLE_SUB, 'password' => null]));
    $this->get(route('google.confirm'))->assertNotFound();
    $this->get(route('password.confirm'))->assertInertia(fn (Assert $page) => $page->where('canConfirmWithGoogle', false));
    expect($this->google->requests)->toBe([]);
})->with(['client_id', 'client_secret']);

it('throttles the round trips', function (): void {
    foreach (range(1, 30) as $attempt) {
        $this->get(route('google.redirect'))->assertRedirect();
    }

    $this->get(route('google.redirect'))->assertTooManyRequests();
});
