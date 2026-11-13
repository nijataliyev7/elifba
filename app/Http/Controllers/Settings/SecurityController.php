<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Settings\LogOutOtherDevices;
use App\Actions\Settings\UpdatePassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\PasswordUpdateRequest;
use App\Http\Requests\Settings\TwoFactorAuthenticationRequest;
use App\Models\User;
use App\Support\RememberedLogin;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Fortify\Features;

class SecurityController extends Controller
{
    /**
     * Show the user's security settings page.
     */
    public function edit(TwoFactorAuthenticationRequest $request, #[CurrentUser] User $user): Response
    {
        $props = [
            'canManageTwoFactor' => Features::canManageTwoFactorAuthentication(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
            // Signed up with Google: they set a password, with no current one (ADR 008).
            'hasPassword' => $user->password !== null,
        ];

        if (Features::canManageTwoFactorAuthentication()) {
            $request->ensureStateIsValid();

            $props['twoFactorEnabled'] = $user->hasEnabledTwoFactorAuthentication();
            $props['requiresConfirmation'] = Features::optionEnabled(Features::twoFactorAuthentication(), 'confirm');
        }

        return Inertia::render('settings/security', $props);
    }

    /**
     * Update the user's password; every other device is logged out, this one
     * stays in (ADR 017).
     */
    public function update(PasswordUpdateRequest $request, #[CurrentUser] User $user, UpdatePassword $update): RedirectResponse
    {
        $previousToken = $user->getRememberToken();

        $update->handle($user, $request->string('password')->value(), $request->session()->getId());

        RememberedLogin::keepThisDevice($request, $user, $previousToken);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.security.password_updated')]);

        return back();
    }

    /**
     * «Başqa cihazlardan çıx»: every other device of the account is logged
     * out, this one stays in (ADR 017 decision 7). The route asks for a fresh
     * password confirmation first.
     */
    public function logOutOtherDevices(Request $request, #[CurrentUser] User $user, LogOutOtherDevices $logOut): RedirectResponse
    {
        $previousToken = $user->getRememberToken();

        $logOut->handle($user, $request->session()->getId());

        RememberedLogin::keepThisDevice($request, $user, $previousToken);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.security.other_devices.done')]);

        return back();
    }
}
