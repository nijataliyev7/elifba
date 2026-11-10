<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CustomWordData;
use App\Domain\Content\Models\CustomWord;
use App\Domain\Content\Support\TeacherLock;
use App\Domain\Content\Support\WordRules;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a teacher's word: its text or its syllable override
 * (docs/architecture.md §3 "Teacher words"). `$data` has passed
 * `CustomWordData::rules()`; under the teacher's lock the word stays unique
 * among the teacher's other words. Games already started keep their
 * snapshots.
 */
final readonly class UpdateCustomWord
{
    /**
     * @throws ValidationException on `text` when the teacher has another word like it
     */
    public function handle(CustomWord $word, CustomWordData $data): CustomWord
    {
        return DB::transaction(function () use ($word, $data): CustomWord {
            TeacherLock::acquire($word->user_id);

            if (CustomWord::isTaken($word->user_id, $data->text, $word->id)) {
                throw ValidationException::withMessages(['text' => WordRules::message('taken')]);
            }

            $word->update($data->toArray());

            return $word;
        });
    }
}
