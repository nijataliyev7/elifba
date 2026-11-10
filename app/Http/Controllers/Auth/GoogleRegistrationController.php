<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Fortify\CreateUserFromGoogle;
use App\Exceptions\GoogleSignInRefused;
use App\Http\Controllers\Controller;
use App\Support\GoogleSignIn;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The completion page of a teacher who signs up with Google (ADR 008): the
 * name and the privacy-note agreement registration asks for (§7), before an
 * account exists.
 */
final class GoogleRegistrationController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        $profile = GoogleSignIn::pending($request->session());

        if ($profile === null) {
            return to_route('login')->withErrors(['google' => __('auth.google.expired')]);
        }

        return Inertia::render('auth/google-register', [
            'email' => $profile->email,
            'name' => $profile->name,
        ]);
    }

    public function store(Request $request, CreateUserFromGoogle $create): RedirectResponse
    {
        $profile = GoogleSignIn::pending($request->session());

        if ($profile === null) {
            return to_route('login')->withErrors(['google' => __('auth.google.expired')]);
        }

        try {
            $user = $create->create($profile, $request->only(['name', 'terms']));
        } catch (GoogleSignInRefused $refused) {
            $request->session()->forget(GoogleSignIn::PENDING);

            return to_route('login')->withErrors(['google' => __($refused->reason)]);
        }

        $request->session()->forget(GoogleSignIn::PENDING);

        event(new Registered($user));

        return GoogleSignIn::logIn($request, $user);
    }

    /** The teacher changed their mind: Google's name and address leave the session. */
    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->forget(GoogleSignIn::PENDING);

        return to_route('login');
    }
}
