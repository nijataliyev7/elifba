<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\PlaySettingsData;
use App\Models\User;

/**
 * Saves the teacher's play settings (docs/architecture.md §4 "Pairing"):
 * `users.phone_remote`. `$data` has passed `PlaySettingsData::rules()`. The
 * next teacher board the teacher opens follows it; a board already open
 * keeps what it loaded with.
 */
final readonly class UpdatePlaySettings
{
    public function handle(User $teacher, PlaySettingsData $data): void
    {
        $teacher->phone_remote = $data->phoneRemote;
        $teacher->save();
    }
}
