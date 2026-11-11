<?php

namespace App\Domain\Family\Actions;

use App\Domain\Family\Data\ChildLettersData;
use App\Domain\Family\Models\Child;

/**
 * Saves the letters a child has learned (docs/architecture.md §3 "Learned
 * letters", ADR 011): lowercase, in alphabet order; null when all 32 are
 * on. They filter the reading levels of the child's games.
 */
final readonly class UpdateChildLetters
{
    public function handle(Child $child, ChildLettersData $data): Child
    {
        $child->update(['learned_letters' => $data->letters]);

        return $child;
    }
}
