<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A created account is a verified teacher with the role and its agreement,
 * as every test before roles assumed (ADR 010 decision 9). `parent()`,
 * `teacherAndParent()` and `withoutRole()` change that. The roles follow the
 * agreements, as `GrantRole` keeps them: an account holds a role exactly
 * when it accepted that role's sentence. `make()` touches no table.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            // Every account accepted the privacy note at registration (§7).
            'terms_accepted_at' => now(),
            'teacher_agreed_at' => now(),
            'parent_agreed_at' => null,
            'area' => Role::Teacher,
        ];
    }

    /**
     * Gives a created account the roles whose agreement it has.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $roles = array_filter(
                Role::cases(),
                static fn (Role $role): bool => $user->getAttribute($role->agreementColumn()) !== null,
            );

            if ($roles !== []) {
                $user->assignRole(...$roles);
            }
        });
    }

    /**
     * A parent and not a teacher.
     */
    public function parent(): static
    {
        return $this->state(fn (array $attributes) => [
            'teacher_agreed_at' => null,
            'parent_agreed_at' => now(),
            'area' => Role::Parent,
        ]);
    }

    /**
     * Both roles, in the teacher area.
     */
    public function teacherAndParent(): static
    {
        return $this->state(fn (array $attributes) => [
            'teacher_agreed_at' => now(),
            'parent_agreed_at' => now(),
            'area' => Role::Teacher,
        ]);
    }

    /**
     * Registered, with no role chosen yet.
     */
    public function withoutRole(): static
    {
        return $this->state(fn (array $attributes) => [
            'teacher_agreed_at' => null,
            'parent_agreed_at' => null,
            'area' => null,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
