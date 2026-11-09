<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user. The teacher must tick that
     * they have read the privacy note and use Elifba with their school's
     * permission (docs/architecture.md §7); the moment is kept on the account.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => __('auth.register.terms_required'),
        ])->validate();

        $user = new User([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
            // The language the teacher registered in stays theirs.
            'locale' => app()->getLocale(),
        ]);

        // Not mass assignable: only registration sets it.
        $user->forceFill(['terms_accepted_at' => now()])->save();

        return $user;
    }
}
