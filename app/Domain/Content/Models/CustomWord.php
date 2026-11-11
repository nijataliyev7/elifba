<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Data\WordData;
use App\Domain\Content\Policies\CustomWordPolicy;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\Syllabifier;
use App\Models\Concerns\BelongsToOwner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\CustomWordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A teacher's own word (docs/architecture.md §1, §3 "Teacher words", ADR
 * 007, ADR 009). It plays in every theme pack and all of the teacher's
 * classes, in `find`, `pop` and the syllable games; it has no picture, so
 * never in `build_word`. Setting `text` sets `word_key` (`word:{lower}`),
 * which the unique index on `(user_id, word_key)` compares. The text may be a
 * child's name: never log it or put it in a URL; routes use the ULID.
 * `{word}` binds only the current teacher's words (`BelongsToOwner`) and
 * passes `CustomWordPolicy`.
 *
 * @property string $id
 * @property int $user_id
 * @property string $text normalized, as written
 * @property string $word_key `word:{lower}`
 * @property list<string>|null $syllables the teacher's override; null = the Syllabifier's split
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $teacher
 */
#[UseFactory(CustomWordFactory::class)]
#[UsePolicy(CustomWordPolicy::class)]
#[Fillable(['text', 'syllables'])]
class CustomWord extends Model
{
    /** @use HasFactory<CustomWordFactory> */
    use BelongsToOwner, HasFactory, HasUlids;

    /**
     * `word:{lower}`, the content key a word plays under.
     */
    public static function keyOf(string $text): string
    {
        return 'word:'.Az::lower($text);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function whereOwner(Builder $query, User $teacher): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $teacher->id);
    }

    public function isOwnedBy(User $teacher): bool
    {
        return $this->user_id === $teacher->id;
    }

    /**
     * Whether the teacher already has this word, compared with Azerbaijani
     * casing, optionally without one of their words (the one being edited).
     */
    public static function isTaken(int $teacherId, string $text, ?string $exceptId = null): bool
    {
        return self::query()
            ->where('user_id', $teacherId)
            ->where('word_key', self::keyOf($text))
            ->when($exceptId !== null, fn (Builder $query) => $query->whereKeyNot($exceptId))
            ->exists();
    }

    /**
     * The word as a pack word: no picture, the override or the Syllabifier's
     * split.
     */
    public function toWordData(): WordData
    {
        return new WordData(
            text: $this->text,
            image: null,
            syllables: $this->syllables ?? Syllabifier::split($this->text),
            overridden: $this->syllables !== null,
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'syllables' => 'array',
        ];
    }

    /**
     * @return Attribute<string, string>
     */
    protected function text(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): array => ['text' => $value, 'word_key' => self::keyOf($value)],
        );
    }
}
