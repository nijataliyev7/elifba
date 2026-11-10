<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // The route sits behind `auth`; a missing user is a wiring mistake, not a validation case.
        $user = $this->user() ?? throw new AuthenticationException;

        return $this->profileRules($user->id);
    }

    /**
     * Changing the address unlinks Google (ADR 008): a teacher who signs in
     * only with Google sets a password first, or they would be locked out.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user() ?? throw new AuthenticationException;

                if ($user->password === null && $this->input('email') !== $user->email) {
                    $validator->errors()->add('email', __('settings.profile.set_password_first'));
                }
            },
        ];
    }
}
