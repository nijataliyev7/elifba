<?php

namespace Database\Factories;

use App\Domain\Classroom\Models\SchoolClass;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SchoolClass>
 */
class SchoolClassFactory extends Factory
{
    /**
     * @var class-string<SchoolClass>
     */
    protected $model = SchoolClass::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $grade = fake()->numberBetween(1, 4);

        return [
            'user_id' => User::factory(),
            'name' => $grade.fake()->randomElement(['A', 'B', 'C', 'D']),
            'grade' => $grade,
            'learned_letters' => null,
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
