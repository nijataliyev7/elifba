<?php

namespace App\Actions\Settings;

use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Remembers the interface language: in the session for everyone, and on the
 * teacher's account when one is logged in, so it follows them to the phone.
 */
final class UpdateLocale
{
    public function handle(string $locale, Session $session, ?User $user): void
    {
        $session->put(SetLocale::SESSION_KEY, $locale);

        if ($user !== null && $user->locale !== $locale) {
            $user->update(['locale' => $locale]);
        }
    }
}
