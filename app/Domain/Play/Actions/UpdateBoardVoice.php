<?php

namespace App\Domain\Play\Actions;

use App\Domain\Play\Data\BoardVoiceData;
use App\Models\User;

/**
 * Saves what the account's boards say (docs/architecture.md §3 "Voice"):
 * `users.board_voice`, null while all three are on, the default. `$data` has
 * passed `BoardVoiceData::rules()`. The next board the account opens
 * follows it, a guest board of its games too; a board already open keeps
 * what it loaded with.
 */
final readonly class UpdateBoardVoice
{
    public function handle(User $user, BoardVoiceData $data): void
    {
        $user->board_voice = $data->prompt && $data->praise && $data->names ? null : $data->toArray();
        $user->save();
    }
}
