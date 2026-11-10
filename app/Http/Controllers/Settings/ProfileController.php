<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\GoogleSignIn;
use App\Support\PasswordConfirmation;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request, #[CurrentUser] User $user): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
            // Deleting the account: a teacher without a password confirms with
            // Google instead (ADR 008).
            'hasPassword' => $user->password !== null,
            'passwordConfirmed' => PasswordConfirmation::isRecent($request),
            'canConfirmWithGoogle' => GoogleSignIn::canConfirm($user),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        $user->fill($request->validated());

        // A new address is unconfirmed, and the Google account linked to the
        // old one no longer vouches for the teacher (ADR 008).
        if ($user->isDirty('email')) {
            $user->forceFill([
                'email_verified_at' => null,
                'email_changed_at' => now(),
                'google_id' => null,
            ]);
        }

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.profile.updated')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, #[CurrentUser] User $user): RedirectResponse
    {
        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
