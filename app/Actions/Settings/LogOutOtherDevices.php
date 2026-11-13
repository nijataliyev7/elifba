<?php

namespace App\Actions\Settings;

use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Ends every login of an account but this device's (docs/architecture.md §7
 * "Remembered login", ADR 017 decision 5): «Başqa cihazlardan çıx», and every
 * password change or reset. The remember token is rotated, so no «Məni
 * xatırla» cookie issued before matches any more, and the account's rows of
 * the `database` session driver are deleted, so their sessions end at once.
 * Keeping this device remembered is RememberedLogin::keepThisDevice()'s.
 */
final class LogOutOtherDevices
{
    /**
     * @param  string|null  $thisSession  the session id of the device that stays in; null ends every session (a reset, made by a guest)
     */
    public function handle(User $user, ?string $thisSession): void
    {
        $user->setRememberToken(Str::random(60));
        $user->save();

        if (Config::get('session.driver') === 'database') {
            DB::connection(Config::get('session.connection'))
                ->table(Config::string('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->when($thisSession !== null, fn ($query) => $query->where('id', '!=', $thisSession))
                ->delete();
        }

        Log::notice('Other devices logged out', ['user_id' => $user->id]);
    }
}
