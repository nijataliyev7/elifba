<?php

namespace App\Actions\Fortify;

use App\Exceptions\GoogleSignInRefused;
use App\Models\User;
use App\Support\GoogleProfile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Finds the teacher a Google account signs in as (ADR 008): the one linked to
 * it, else the one with its e-mail address, whom it links. Null when no
 * teacher has the address: a new teacher, for the completion page.
 */
final class LinkGoogleAccount
{
    /** @throws GoogleSignInRefused */
    public function handle(GoogleProfile $profile): ?User
    {
        // Only an address Google verified, and still vouches for, proves who the teacher is.
        if (! $profile->emailVerified) {
            throw new GoogleSignInRefused('auth.google.unverified');
        }

        if (! $profile->vouchesForAddress()) {
            throw new GoogleSignInRefused('auth.google.not_authoritative');
        }

        $linked = User::query()->where('google_id', $profile->id)->first();

        if ($linked !== null) {
            return $linked;
        }

        $user = DB::transaction(function () use ($profile): ?User {
            $matches = User::query()
                ->whereRaw('lower(email) = ?', [$profile->email])
                ->lockForUpdate()
                ->get();

            $user = $matches->firstWhere('email', $profile->email)
                ?? ($matches->count() === 1 ? $matches->first() : null);

            if ($user === null) {
                // Two accounts differing only in case: which one is not ours to guess.
                return $matches->isEmpty() ? null : throw new GoogleSignInRefused('auth.google.failed');
            }

            if ($user->google_id !== null) {
                throw new GoogleSignInRefused('auth.google.other_account');
            }

            if ($user->email_verified_at === null) {
                // A teacher changed to this address and has not confirmed it:
                // the account is theirs, whoever holds the address now.
                if ($user->email_changed_at !== null) {
                    throw new GoogleSignInRefused('auth.google.unconfirmed_account');
                }

                $this->dropCredentials($user);
            }

            $user->forceFill(['google_id' => $profile->id])->save();

            return $user;
        });

        if ($user !== null) {
            Log::info('Google account linked', ['user_id' => $user->id]);
        }

        return $user;
    }

    /**
     * The address the account was registered with was never confirmed, so
     * whoever registered it may not own it. Google has now proven the owner: the account becomes theirs, and
     * every way in that someone else may have set up is closed.
     */
    private function dropCredentials(User $user): void
    {
        $user->forceFill([
            'email_verified_at' => now(),
            'password' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'remember_token' => Str::random(60),
        ]);

        $user->passkeys()->delete();

        if (Config::get('session.driver') === 'database') {
            DB::connection(Config::get('session.connection'))
                ->table(Config::string('session.table', 'sessions'))
                ->where('user_id', $user->id)
                ->delete();
        }

        Log::notice('Google sign-in took over an unconfirmed account and dropped its credentials', ['user_id' => $user->id]);
    }
}
