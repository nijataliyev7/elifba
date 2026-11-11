<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\BoardSoundsData;
use App\Models\User;

/**
 * Saves the sounds an account chose for its boards (docs/architecture.md
 * §3 "Sounds"): `users.board_sounds`, only the moments with a choice, null
 * when every moment plays the pack's own. `$data` has passed
 * `BoardSoundsData::rules()`. The next board the account opens follows it,
 * a guest board of its games too; a board already open keeps what it
 * loaded with.
 */
final readonly class UpdateBoardSounds
{
    public function handle(User $user, BoardSoundsData $data): void
    {
        $chosen = array_filter($data->keys, static fn (?string $key): bool => $key !== null);

        $user->board_sounds = $chosen === [] ? null : $chosen;
        $user->save();
    }
}
