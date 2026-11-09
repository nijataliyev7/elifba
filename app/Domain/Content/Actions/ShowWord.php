<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\HiddenWordData;
use App\Domain\Content\Models\HiddenWord;
use App\Models\User;

/**
 * Shows a built-in word the teacher hid again (docs/architecture.md §3
 * "Teacher words"). Showing a word that is not hidden changes nothing.
 */
final readonly class ShowWord
{
    public function handle(User $teacher, HiddenWordData $data): void
    {
        HiddenWord::query()
            ->where('user_id', $teacher->id)
            ->where('pack', $data->pack)
            ->where('word_key', $data->key())
            ->delete();
    }
}
