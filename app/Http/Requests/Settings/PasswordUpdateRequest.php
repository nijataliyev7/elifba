<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Support\PasswordConfirmation;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PasswordUpdateRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        // A teacher who signed up with Google has no current password to type;
        // they confirmed with Google to reach the page, checked below (ADR 008).
        if ($this->withoutPassword()) {
            return ['password' => $this->passwordRules()];
        }

        return [
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ];
    }

    /** @return array<int, Closure(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->withoutPassword() && ! PasswordConfirmation::isRecent($this)) {
                    $validator->errors()->add('password', __('auth.google.confirm_first'));
                }
            },
        ];
    }

    private function withoutPassword(): bool
    {
        // The route sits behind `auth`; a missing user is a wiring mistake, not a validation case.
        $user = $this->user() ?? throw new AuthenticationException;

        return $user->password === null;
    }
}
