<?php

use App\Actions\Settings\LogOutOtherDevices;
use App\Models\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/*
 * «Məni xatırla» (docs/architecture.md §7 "Remembered login", ADR 017): a
 * 30-day recaller cookie, on by default, and every way to cut it. Sessions
 * are rows of the `database` driver, as in production, and every device is
 * a browser holding only its cookies (TestCase::fromBrowser()).
 */

beforeEach(function (): void {
    config(['session.driver' => 'database']);
    $this->fromBrowser();
});

function recallerName(): string
{
    $guard = Auth::guard('web');
    assert($guard instanceof SessionGuard);

    return $guard->getRecallerName();
}

/**
 * The session and recaller cookies a response leaves in the browser, on top
 * of those it had: a cookie the response deletes is gone.
 *
 * @param  array<string, string>  $before
 * @return array<string, string>
 */
function browserAfter(TestResponse $response, array $before = []): array
{
    $cookies = $before;

    foreach ([config('session.cookie'), recallerName()] as $name) {
        $cookie = $response->getCookie($name, decrypt: false);

        if ($cookie === null) {
            continue;
        }

        if ($cookie->getExpiresTime() !== 0 && $cookie->getExpiresTime() < time()) {
            unset($cookies[$name]);

            continue;
        }

        $cookies[$name] = (string) $response->getCookie($name)?->getValue();
    }

    return $cookies;
}

/**
 * Logs in through the e-mail form on a new browser.
 *
 * @return array<string, string> the browser's cookies afterwards
 */
function logInOnNewBrowser(TestCase $test, User $user, bool $remember = true): array
{
    $response = $test->fromBrowser()->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
        ...($remember ? ['remember' => '1'] : []),
    ]);

    $response->assertRedirect(route('start', absolute: false));

    return browserAfter($response);
}

/** Whether a browser holding these cookies is logged in, on a page behind `auth`. */
function isLoggedIn(TestCase $test, array $cookies): bool
{
    $response = $test->fromBrowser($cookies)->get(route('profile.edit'));

    return $response->isOk();
}

function sessionRow(array $cookies): bool
{
    return DB::table('sessions')->where('id', $cookies[config('session.cookie')])->exists();
}

/** The token a recaller cookie carries (`id|token|hash`). */
function recallerToken(array $cookies): string
{
    return explode('|', $cookies[recallerName()])[1];
}

it('keeps a remembered login for 30 days, not Laravel\'s 400', function (): void {
    $this->freezeTime();
    $user = User::factory()->create();

    $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password', 'remember' => '1']);

    expect(config('auth.guards.web.remember'))->toBe(43200)
        ->and($response->getCookie(recallerName(), decrypt: false)?->getExpiresTime())
        ->toEqualWithDelta(now()->addDays(30)->getTimestamp(), 60);
});

it('sets no recaller when the box is unticked', function (): void {
    $user = User::factory()->create();

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('start', absolute: false))
        ->assertCookieMissing(recallerName());
    $this->assertAuthenticatedAs($user);
});

it('remembers a registration as its box says', function (bool $remember): void {
    $response = $this->post(route('register.store'), [
        'name' => 'Aygün',
        'email' => 'aygun@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'terms' => '1',
        ...($remember ? ['remember' => '1'] : []),
    ]);

    $response->assertRedirect(route('start', absolute: false));
    expect($response->getCookie(recallerName(), decrypt: false) !== null)->toBe($remember);
})->with(['ticked' => true, 'unticked' => false]);

it('logs a remembered browser back in after its session ended', function (): void {
    $user = User::factory()->create();
    $remembered = logInOnNewBrowser($this, $user);
    $plain = logInOnNewBrowser($this, $user, remember: false);

    expect(isLoggedIn($this, $remembered))->toBeTrue()
        ->and(isLoggedIn($this, $plain))->toBeTrue();

    $this->travel(config('session.lifetime') + 1)->minutes();

    expect(isLoggedIn($this, $plain))->toBeFalse()
        ->and(isLoggedIn($this, [config('session.cookie') => $remembered[config('session.cookie')]]))->toBeFalse();

    $this->fromBrowser($remembered)->get(route('profile.edit'))->assertOk();
    $this->assertAuthenticatedAs($user);
});

it('takes a same-origin Inertia request from a page left open across the session end', function (): void {
    $user = User::factory()->create(['name' => 'Köhnə ad']);
    $remembered = logInOnNewBrowser($this, $user);
    $staleToken = app('session.store')->token();

    // From here CSRF is checked as in production (Laravel skips it in unit tests).
    app()->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app->make('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });

    $this->travel(config('session.lifetime') + 1)->minutes();

    $update = ['name' => 'Yeni ad', 'email' => $user->email];
    $inertia = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'X-CSRF-TOKEN' => (string) $staleToken];

    // Without the browser's word for it, the stale token is refused…
    $this->fromBrowser($remembered)->patch(route('profile.update'), $update, $inertia)->assertStatus(419);

    // …and with it, the request goes through, logged in again from the cookie.
    $this->fromBrowser($remembered)
        ->patch(route('profile.update'), $update, [...$inertia, 'Sec-Fetch-Site' => 'same-origin'])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->name)->toBe('Yeni ad');
});

it('logs every other device out when the password changes, and keeps this one remembered', function (): void {
    $user = User::factory()->create();
    $other = logInOnNewBrowser($this, $user);
    $this1 = logInOnNewBrowser($this, $user);
    $oldToken = recallerToken($this1);

    $response = $this->fromBrowser($this1)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response->assertSessionHasNoErrors()->assertRedirect(route('security.edit'));
    $this1 = browserAfter($response, $this1);

    $token = (string) $user->refresh()->remember_token;
    expect($token)->not->toBe($oldToken)
        ->and(recallerToken($this1))->toBe($token)
        ->and(sessionRow($other))->toBeFalse()
        ->and(sessionRow($this1))->toBeTrue()
        ->and(isLoggedIn($this, $other))->toBeFalse()
        ->and(isLoggedIn($this, [recallerName() => $other[recallerName()]]))->toBeFalse()
        ->and(isLoggedIn($this, $this1))->toBeTrue()
        ->and(isLoggedIn($this, [recallerName() => $this1[recallerName()]]))->toBeTrue();
});

it('keeps a device without «Məni xatırla» on its plain session after a password change', function (): void {
    $user = User::factory()->create();
    $plain = logInOnNewBrowser($this, $user, remember: false);

    $response = $this->fromBrowser($plain)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response->assertSessionHasNoErrors()->assertCookieMissing(recallerName());
    expect(isLoggedIn($this, browserAfter($response, $plain)))->toBeTrue();
});

it('mints no login from a recaller cookie that is not this account\'s live one', function (Closure $leftBehind): void {
    $user = User::factory()->create();
    $someoneElse = User::factory()->create();
    $recaller = $leftBehind($this, $user, $someoneElse);

    // The account logs in without «Məni xatırla» on a browser that still holds that cookie.
    $browser = [...logInOnNewBrowser($this, $user, remember: false), recallerName() => $recaller];

    $response = $this->fromBrowser($browser)
        ->from(route('security.edit'))
        ->put(route('user-password.update'), [
            'current_password' => 'password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

    $response->assertSessionHasNoErrors()->assertCookieMissing(recallerName());
    $this->assertAuthenticatedAs($user);
})->with([
    'another account\'s' => [fn (TestCase $test, User $user, User $someoneElse): string => logInOnNewBrowser($test, $someoneElse)[recallerName()]],
    'this account\'s, revoked before' => [function (TestCase $test, User $user): string {
        $stale = logInOnNewBrowser($test, $user)[recallerName()];
        app(LogOutOtherDevices::class)->handle($user, null);

        return $stale;
    }],
]);

it('logs the other devices out with «Başqa cihazlardan çıx», after a fresh confirmation', function (): void {
    $user = User::factory()->create();
    $other = logInOnNewBrowser($this, $user);
    $this1 = logInOnNewBrowser($this, $user);
    $before = (string) $user->remember_token;

    // The page asked for the password; a request without it goes there first.
    $this->fromBrowser($this1)->delete(route('other-devices.destroy'))->assertRedirect(route('password.confirm'));
    expect($user->refresh()->remember_token)->toBe($before)
        ->and(isLoggedIn($this, $other))->toBeTrue();

    $confirmed = $this->fromBrowser($this1)->post(route('password.confirm.store'), ['password' => 'password']);
    $this1 = browserAfter($confirmed, $this1);

    $response = $this->fromBrowser($this1)
        ->from(route('security.edit'))
        ->delete(route('other-devices.destroy'));

    $response->assertRedirect(route('security.edit'));
    $this1 = browserAfter($response, $this1);

    expect($user->refresh()->remember_token)->not->toBe($before)
        ->and(recallerToken($this1))->toBe($user->remember_token)
        ->and(sessionRow($other))->toBeFalse()
        ->and(isLoggedIn($this, $other))->toBeFalse()
        ->and(isLoggedIn($this, $this1))->toBeTrue()
        ->and(isLoggedIn($this, [recallerName() => $this1[recallerName()]]))->toBeTrue();
});

it('logs every device out when the password is reset by mail', function (): void {
    $user = User::factory()->create();
    $other = logInOnNewBrowser($this, $user);
    $token = Password::broker()->createToken($user);

    $this->fromBrowser()->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password',
        'password_confirmation' => 'new-password',
    ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(sessionRow($other))->toBeFalse()
        ->and(isLoggedIn($this, $other))->toBeFalse()
        ->and(isLoggedIn($this, [recallerName() => $other[recallerName()]]))->toBeFalse();
});

it('logs only this device out with «Çıxış»', function (): void {
    $user = User::factory()->create();
    $other = logInOnNewBrowser($this, $user);
    $this1 = logInOnNewBrowser($this, $user);
    $token = $user->refresh()->remember_token;

    $response = $this->fromBrowser($this1)->post(route('logout'));

    $response->assertRedirect('/');
    $this->assertGuest();
    $this1After = browserAfter($response, $this1);

    expect($this1After)->not->toHaveKey(recallerName())
        ->and(sessionRow($this1))->toBeFalse()
        ->and(isLoggedIn($this, $this1After))->toBeFalse()
        ->and($user->refresh()->remember_token)->toBe($token)
        ->and(sessionRow($other))->toBeTrue()
        ->and(isLoggedIn($this, $other))->toBeTrue()
        ->and(isLoggedIn($this, [recallerName() => $other[recallerName()]]))->toBeTrue();
});
