<?php

namespace App\Data;

use App\Enums\Role;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A role the account takes, with that role's sentence accepted
 * (docs/architecture.md §1 "Accounts and roles", §7 "Agreements", ADR 010,
 * ADR 013): the choice after registration (`/role`) and the second role
 * (Settings → «Rollar») alike.
 */
final readonly class RoleChoiceData
{
    public function __construct(
        public Role $role,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::enum(Role::class)],
            'agreement' => ['accepted'],
        ];
    }

    /**
     * The messages a person can act on: which role, and which sentence.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'role' => self::text('roles.errors.role'),
            'agreement' => self::text('roles.errors.agreement'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(array $input): self
    {
        $validated = Validator::make($input, self::rules(), self::messages())->validate();

        return new self(Role::from($validated['role']));
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
