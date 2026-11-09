<?php

namespace Database\Factories;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Support\Avatars;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Display names are random Azerbaijani first names; a test that depends on
 * uniqueness or order sets them itself.
 *
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    /**
     * @var class-string<Student>
     */
    protected $model = Student::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'school_class_id' => SchoolClass::factory(),
            'display_name' => fake('az_AZ')->firstName(),
            'avatar' => fake()->randomElement(Avatars::keys()),
            // Appends to the class, as AddStudents does.
            'position' => fn (array $attributes): int => 1 + (int) Student::query()
                ->where('school_class_id', $attributes['school_class_id'])
                ->max('position'),
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn (): array => ['archived_at' => now()]);
    }
}
