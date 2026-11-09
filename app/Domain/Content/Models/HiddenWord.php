<?php

namespace App\Domain\Content\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A built-in word a teacher has hidden from their own games
 * (docs/architecture.md §1, §3 "Teacher words", ADR 007). Other teachers
 * still play it; `ShowWord` deletes the row. A row whose word a later pack
 * version removed does nothing.
 *
 * @property int $id
 * @property int $user_id
 * @property string $pack
 * @property string $word_key `word:{lower}` of a built-in word of the pack
 * @property CarbonImmutable|null $created_at
 * @property-read User $teacher
 */
#[Fillable(['pack', 'word_key'])]
class HiddenWord extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
