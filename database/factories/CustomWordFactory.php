<?php

namespace Database\Factories;

use App\Domain\Content\Models\CustomWord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A made-up three-syllable word (consonant + vowel, three times) in the
 * fruit pack, which fits the long word level and is in no pack. A test that
 * depends on the word sets it itself.
 *
 * @extends Factory<CustomWord>
 */
class CustomWordFactory extends Factory
{
    /**
     * @var class-string<CustomWord>
     */
    protected $model = CustomWord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $syllable = static fn (): string => fake()->randomElement(['b', 'd', 'k', 'l', 'm', 'n', 'r', 's', 't', 'z'])
            .fake()->randomElement(['a', 'e', 'i', 'o', 'u']);

        return [
            'user_id' => User::factory(),
            'pack' => 'fruit',
            'text' => $syllable().$syllable().$syllable(),
            'syllables' => null,
        ];
    }
}
