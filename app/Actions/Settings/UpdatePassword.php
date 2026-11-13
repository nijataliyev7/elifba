<?php

namespace App\Actions\Settings;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Sets the account's password from the security page (validated by
 * PasswordUpdateRequest: the current password, or for an account made with
 * Google a fresh confirmation, ADR 008) and ends every other device's login
 * with it (ADR 017 decision 5): whoever knew the old one is out.
 */
final class UpdatePassword
{
    public function __construct(private readonly LogOutOtherDevices $logOutOtherDevices) {}

    /**
     * @param  string|null  $thisSession  the session id of the device that stays in
     */
    public function handle(User $user, #[SensitiveParameter] string $password, ?string $thisSession): void
    {
        DB::transaction(function () use ($user, $password, $thisSession): void {
            $user->forceFill(['password' => $password])->save();

            $this->logOutOtherDevices->handle($user, $thisSession);
        });
    }
}
