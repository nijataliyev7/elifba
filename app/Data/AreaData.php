<?php

namespace App\Data;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The area an account with both roles moves to (docs/architecture.md §1
 * "Accounts and roles", ADR 010 decision 7): one of the roles it holds.
 */
final readonly class AreaData
{
    public function __construct(
        public Role $area,
    ) {}

    /**
     * `Rule::in`, not `Rule::enum()->only()`: an empty `only` would allow
     * every role to an account that holds none.
     *
     * @return array<string, mixed>
     */
    public static function rules(User $user): array
    {
        return [
            'area' => ['required', 'string', Rule::in(array_map(static fn (Role $role): string => $role->value, $user->roleList()))],
        ];
    }

    /**
     * @param  array<array-key, mixed>  $input
     *
     * @throws ValidationException
     */
    public static function validate(User $user, array $input): self
    {
        $message = __('roles.errors.area');

        $validated = Validator::make($input, self::rules($user), [
            'area' => is_string($message) ? $message : 'roles.errors.area',
        ])->validate();

        return new self(Role::from($validated['area']));
    }
}
