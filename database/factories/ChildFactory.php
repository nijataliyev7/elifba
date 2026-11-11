<?php

namespace Database\Factories;

use App\Domain\Content\Support\Avatars;
use App\Domain\Family\Models\Child;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A parent's child: a random Azerbaijani first name, an avatar, every
 * letter learned, appended to the parent's order. The parent is created
 * with the parent role; a test that depends on the name sets it itself.
 *
 * @extends Factory<Child>
 */
class ChildFactory extends Factory
{
    /**
     * @var class-string<Child>
     */
    protected $model = Child::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->parent(),
            'display_name' => fake('az_AZ')->firstName(),
            'avatar' => fake()->randomElement(Avatars::keys()),
            'learned_letters' => null,
            // Appends to the parent's children, as AddChild does.
            'position' => fn (array $attributes): int => 1 + (int) Child::query()
                ->where('user_id', $attributes['user_id'])
                ->max('position'),
        ];
    }
}
