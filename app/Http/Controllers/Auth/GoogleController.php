<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\LinkGoogleAccount;
use App\Exceptions\GoogleSignInRefused;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\GoogleProfile;
use App\Support\GoogleSignIn;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Two\InvalidStateException;

/**
 * The round trips to Google (docs/architecture.md §7 "Google sign-in",
 * ADR 008): to sign in or sign up, and to confirm the password of a teacher
 * who signs in with Google. One callback serves both; the intent the
 * redirect left in the session says which.
 */
final class GoogleController extends Controller
{
    /** Sign in or sign up: off to Google's account chooser. */
    public function redirect(Request $request): RedirectResponse
    {
        $request->session()->put(GoogleSignIn::INTENT, ['for' => 'login']);

        return GoogleSignIn::provider($request)->redirect();
    }

    /** Confirm who the teacher is with the Google account linked to them. */
    public function confirm(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        if ($user->google_id === null) {
            return to_route('password.confirm')->withErrors(['google' => __('auth.google.not_linked')]);
        }

        // With a password, the password is the proof (GoogleSignIn::canConfirm).
        if (! GoogleSignIn::canConfirm($user)) {
            return to_route('password.confirm');
        }

        $request->session()->put(GoogleSignIn::INTENT, ['for' => 'confirm', 'user' => $user->id]);

        return GoogleSignIn::provider($request)->redirect();
    }

    /** Google's answer. */
    public function callback(Request $request, LinkGoogleAccount $link): RedirectResponse
    {
        $intent = $request->session()->pull(GoogleSignIn::INTENT);
        $for = is_array($intent) ? ($intent['for'] ?? null) : null;

        // No round trip started here, in this session: nothing to finish.
        if (! is_array($intent) || ! in_array($for, ['login', 'confirm'], true)) {
            return $this->failed('login', 'auth.google.failed');
        }

        [$back, $failed, $cancelled] = $for === 'confirm'
            ? ['password.confirm', 'auth.google.confirm_failed', 'auth.google.confirm_cancelled']
            : ['login', 'auth.google.failed', 'auth.google.cancelled'];

        // The teacher closed Google's page or said no.
        if ($request->filled('error')) {
            $request->session()->forget(['state', 'code_verifier']);

            return $this->failed($back, $cancelled);
        }

        try {
            // Socialite checks the OAuth `state` against the session first.
            $profile = GoogleProfile::fromGoogle(GoogleSignIn::provider($request)->user());
        } catch (InvalidStateException|GuzzleException $exception) {
            Log::notice('Google sign-in failed', ['reason' => $exception::class]);

            return $this->failed($back, $failed);
        }

        if ($profile === null) {
            return $this->failed($back, $failed);
        }

        return $for === 'confirm'
            ? $this->confirmed($request, $intent['user'] ?? null, $profile)
            : $this->signIn($request, $profile, $link);
    }

    private function signIn(Request $request, GoogleProfile $profile, LinkGoogleAccount $link): RedirectResponse
    {
        // Signed in meanwhile, in another tab: nothing to change.
        if ($request->user() !== null) {
            return redirect()->intended(GoogleSignIn::home());
        }

        try {
            $user = $link->handle($profile);
        } catch (GoogleSignInRefused $refused) {
            return $this->failed('login', $refused->reason);
        }

        if ($user === null) {
            GoogleSignIn::keepPending($request->session(), $profile);

            return to_route('google.register');
        }

        return GoogleSignIn::logIn($request, $user);
    }

    private function confirmed(Request $request, mixed $userId, GoogleProfile $profile): RedirectResponse
    {
        $user = $request->user();

        // Logged out, or another teacher logged in, since the round trip began.
        if (! $user instanceof User || $user->id !== $userId) {
            return to_route('login');
        }

        if ($user->google_id === null || ! hash_equals($user->google_id, $profile->id)) {
            return $this->failed('password.confirm', 'auth.google.wrong_account');
        }

        $request->session()->passwordConfirmed();

        return redirect()->intended(route('profile.edit'));
    }

    private function failed(string $route, string $reason): RedirectResponse
    {
        return to_route($route)->withErrors(['google' => __($reason)]);
    }
}
