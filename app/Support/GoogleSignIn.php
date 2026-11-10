<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Two\GoogleProvider;

/**
 * Google sign-in for teachers (docs/architecture.md §7 "Google sign-in",
 * ADR 008): whether it is on, the Google client, the session keys of a round
 * trip, and how a teacher it found is let in.
 */
final class GoogleSignIn
{
    /** What the round trip to Google is for: `['for' => 'login']` or `['for' => 'confirm', 'user' => id]`. */
    public const string INTENT = 'google.intent';

    /** A new teacher's Google profile, kept until the completion page. */
    public const string PENDING = 'google.pending';

    public const int PENDING_MINUTES = 10;

    /** On only when the operator set both halves of an OAuth client. */
    public static function enabled(): bool
    {
        return filled(Config::get('services.google.client_id'))
            && filled(Config::get('services.google.client_secret'));
    }

    /**
     * Google's OAuth client for this request: the scopes `openid email profile`,
     * Google's account chooser (school browsers are shared) and PKCE. The
     * redirect URI is the configured path on the host the teacher used. Built
     * per request rather than through Socialite's manager, which would keep
     * the first request it saw.
     */
    public static function provider(Request $request): GoogleProvider
    {
        return (new GoogleProvider(
            $request,
            Config::string('services.google.client_id'),
            Config::string('services.google.client_secret'),
            url(Config::string('services.google.redirect')),
            Config::array('services.google.guzzle', []),
        ))
            ->setScopes(['openid', 'email', 'profile'])
            ->with(['prompt' => 'select_account'])
            ->enablePKCE();
    }

    /**
     * Whether the teacher confirms who they are with Google: only a teacher
     * linked to Google who has no password. With a password, the password
     * stays the proof — a Google session left open on a school computer
     * must not stand in for it.
     */
    public static function canConfirm(User $user): bool
    {
        return self::enabled() && $user->google_id !== null && $user->password === null;
    }

    /** Keeps a new teacher's profile for the completion page. */
    public static function keepPending(Session $session, GoogleProfile $profile): void
    {
        $session->put(self::PENDING, [
            ...$profile->toArray(),
            'expires_at' => Date::now()->addMinutes(self::PENDING_MINUTES)->unix(),
        ]);
    }

    /** The profile waiting for the completion page; null when there is none or it expired. */
    public static function pending(Session $session): ?GoogleProfile
    {
        $pending = $session->get(self::PENDING);
        $expiresAt = is_array($pending) ? ($pending['expires_at'] ?? null) : null;

        if (! is_array($pending) || ! is_int($expiresAt) || $expiresAt < Date::now()->unix()) {
            $session->forget(self::PENDING);

            return null;
        }

        return GoogleProfile::fromArray($pending);
    }

    /**
     * Lets a teacher in as a password login would: a teacher with confirmed
     * two-factor authentication gets Fortify's challenge (as its
     * `RedirectIfTwoFactorAuthenticatable` does); everyone else gets a fresh
     * session, never "remember me" (§7 "Shared computers").
     */
    public static function logIn(Request $request, User $user): RedirectResponse
    {
        if (Features::enabled(Features::twoFactorAuthentication()) && $user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put([
                'login.id' => $user->getKey(),
                'login.remember' => false,
            ]);

            TwoFactorAuthenticationChallenged::dispatch($user);

            return to_route('two-factor.login');
        }

        Auth::guard(Config::string('fortify.guard'))->login($user);

        $request->session()->regenerate();

        return redirect()->intended(self::home());
    }

    /** Where a login lands: Fortify's, the classes. */
    public static function home(): string
    {
        return Fortify::redirects('login');
    }
}
