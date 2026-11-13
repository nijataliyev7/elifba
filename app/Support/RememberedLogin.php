<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Recaller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;

/**
 * The device that cut the account's other logins (docs/architecture.md §7
 * "Remembered login", ADR 017 decision 6), as panelaz's
 * `reissueRecallerForCurrentDevice` does.
 */
final class RememberedLogin
{
    /**
     * Gives this device a «Məni xatırla» cookie with the rotated token, only
     * when the request carried a live one of this account: a valid recaller
     * whose id is this account's and whose token is the one from before the
     * rotation. The cookie's name is the same for every account, so a cookie
     * left on a shared browser by someone else, or one already revoked, must
     * not mint a 30-day login for the person now at the keyboard. A device
     * without one keeps its plain session, as it was.
     *
     * Call it after LogOutOtherDevices, with the token read before it.
     */
    public static function keepThisDevice(Request $request, User $user, ?string $previousToken): void
    {
        $guard = Auth::guard(Config::string('fortify.guard'));

        if (! $guard instanceof SessionGuard || ! $request->hasSession()) {
            return;
        }

        if ($previousToken === null || $previousToken === '') {
            return;
        }

        $cookie = $request->cookies->get($guard->getRecallerName());

        if (! is_string($cookie) || $cookie === '') {
            return;
        }

        $recaller = new Recaller($cookie);

        if (
            ! $recaller->valid()
            || (string) $recaller->id() !== (string) $user->getKey()
            || ! hash_equals($previousToken, $recaller->token())
        ) {
            return;
        }

        // Queues the new cookie and moves the session to a new id (the old
        // row is destroyed; LogOutOtherDevices kept only that one).
        $guard->login($user, true);
    }
}
