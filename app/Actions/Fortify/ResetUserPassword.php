<?php

namespace App\Actions\Fortify;

use App\Actions\Settings\LogOutOtherDevices;
use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    public function __construct(private readonly LogOutOtherDevices $logOutOtherDevices) {}

    /**
     * Validate and reset the user's forgotten password, and end every login
     * of the account (ADR 017 decision 5): the reset is made by a guest, so
     * no device is kept. Fortify then cycles the remember token once more.
     *
     * @param  array<string, string>  $input
     */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input): void {
            $user->forceFill([
                'password' => $input['password'],
            ])->save();

            $this->logOutOtherDevices->handle($user, null);
        });
    }
}
