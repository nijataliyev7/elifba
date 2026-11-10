<?php

namespace App\Actions\Fortify;

use App\Concerns\ProfileValidationRules;
use App\Exceptions\GoogleSignInRefused;
use App\Models\User;
use App\Support\GoogleProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Creates the account of a teacher who signed up with Google (ADR 008), from
 * the completion page: the name (Google's, editable) and the same privacy-note
 * agreement as registration (docs/architecture.md §7). Google verified the
 * address; there is no password until the teacher sets one.
 */
final class CreateUserFromGoogle
{
    use ProfileValidationRules;

    /**
     * @param  array<string, mixed>  $input  the completion form: `name`, `terms`
     *
     * @throws GoogleSignInRefused when the address or the Google account was taken meanwhile
     */
    public function create(GoogleProfile $profile, array $input): User
    {
        $data = Validator::make([
            'name' => $input['name'] ?? null,
            'terms' => $input['terms'] ?? null,
            'email' => $profile->email,
            'google_id' => $profile->id,
        ], [
            'name' => $this->nameRules(),
            'terms' => ['accepted'],
            'email' => $this->emailRules(),
            'google_id' => ['required', 'string', 'max:255', Rule::unique(User::class, 'google_id')],
        ], [
            'terms.accepted' => __('auth.register.terms_required'),
        ])->validate();

        $user = new User([
            'name' => $data['name'],
            'email' => $profile->email,
            // The language the teacher registered in stays theirs.
            'locale' => app()->getLocale(),
        ]);

        // Not mass assignable: only sign-up sets them.
        $user->forceFill([
            'google_id' => $profile->id,
            'email_verified_at' => now(),
            'terms_accepted_at' => now(),
        ]);

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // Taken between the check and the insert (a second tab, a second
            // form). Reported as a refusal: the exception's SQL holds the
            // teacher's name and address, which never go into a log.
            throw new GoogleSignInRefused('auth.google.failed');
        }

        return $user;
    }
}
