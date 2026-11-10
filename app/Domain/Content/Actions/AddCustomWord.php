<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Support\TeacherLock;
use App\Domain\Content\Support\WordRules;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a teacher's own word, which plays in every theme pack
 * (docs/architecture.md §3 "Teacher words", ADR 007, ADR 009). `$data` has
 * passed `CustomWordData::rules()`; under the teacher's lock this checks
 * what needs the teacher: the word is not already one of theirs, and they
 * keep at most `elifba.custom_words.max_per_teacher` words.
 */
final readonly class AddCustomWord
{
    /**
     * @throws ValidationException on `text` when the teacher has the word or has too many words
     */
    public function handle(User $teacher, CustomWordData $data): CustomWord
    {
        return DB::transaction(function () use ($teacher, $data): CustomWord {
            TeacherLock::acquire($teacher->id);

            $max = Config::integer('elifba.custom_words.max_per_teacher');

            if (CustomWord::query()->where('user_id', $teacher->id)->count() >= $max) {
                throw ValidationException::withMessages(['text' => WordRules::message('too_many', ['max' => $max])]);
            }

            if (CustomWord::isTaken($teacher->id, $data->text)) {
                throw ValidationException::withMessages(['text' => WordRules::message('taken')]);
            }

            $word = new CustomWord($data->toArray());
            $word->user_id = $teacher->id;
            $word->save();

            return $word;
        });
    }
}
