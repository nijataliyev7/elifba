<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Models\CustomWord;

/**
 * Deletes a teacher's word for good (docs/architecture.md §3 "Teacher
 * words"). Games already started keep it in their round snapshots.
 */
final readonly class DeleteCustomWord
{
    public function handle(CustomWord $word): void
    {
        $word->delete();
    }
}
