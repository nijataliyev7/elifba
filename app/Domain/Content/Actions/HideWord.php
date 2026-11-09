<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\HiddenWordData;
use App\Domain\Content\Models\HiddenWord;
use App\Domain\Content\Support\TeacherLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Hides a built-in word from the teacher's own games (docs/architecture.md
 * §3 "Teacher words", ADR 007); other teachers still play it. `$data` has
 * passed `HiddenWordData::rules()`. Hiding a hidden word changes nothing.
 */
final readonly class HideWord
{
    public function handle(User $teacher, HiddenWordData $data): HiddenWord
    {
        return DB::transaction(function () use ($teacher, $data): HiddenWord {
            TeacherLock::acquire($teacher->id);

            $hidden = HiddenWord::query()
                ->where('user_id', $teacher->id)
                ->where('pack', $data->pack)
                ->where('word_key', $data->key())
                ->first();

            if ($hidden !== null) {
                return $hidden;
            }

            $hidden = new HiddenWord(['pack' => $data->pack, 'word_key' => $data->key()]);
            $hidden->user_id = $teacher->id;
            $hidden->save();

            return $hidden;
        });
    }
}
