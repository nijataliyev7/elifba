<?php

namespace App\Domain\Classroom\Actions;

use App\Domain\Classroom\Data\LearnedLettersData;
use App\Domain\Classroom\Models\SchoolClass;

/**
 * Saves the letters a class has learned (docs/architecture.md §3): lowercase,
 * in alphabet order; null when all 32 are on.
 */
final readonly class UpdateLearnedLetters
{
    public function handle(SchoolClass $class, LearnedLettersData $data): SchoolClass
    {
        $class->update(['learned_letters' => $data->letters]);

        return $class;
    }
}
