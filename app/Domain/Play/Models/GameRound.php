<?php

namespace App\Domain\Play\Models;

use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundProgressData;
use Carbon\CarbonImmutable;
use Database\Factories\GameRoundFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One round of a game: its snapshot and its result (docs/architecture.md
 * §1, §2, §5). The snapshot never changes; `progress`, `misses` and the
 * result columns are written only by `RecordBoardEvents`. A solved round
 * carries its points, the student it was credited to (null for the whole
 * class) and its Baku `played_on` and Monday `week_start`.
 *
 * @phpstan-import-type RoundPayload from RoundData
 *
 * @property string $id
 * @property string $game_session_id
 * @property string $school_class_id copied from the game, for ranking queries
 * @property int $position 0-based
 * @property RoundPayload $payload RoundData without id, index and total
 * @property list<string> $progress item ids hit correctly so far, in order
 * @property int $misses
 * @property CarbonImmutable|null $solved_at
 * @property CarbonImmutable|null $skipped_at
 * @property string|null $student_id who solved it
 * @property int $points
 * @property string|null $played_on Baku date of `solved_at`, `Y-m-d` (`SchoolCalendar`)
 * @property string|null $week_start the Monday of `played_on`, `Y-m-d`
 * @property-read GameSession $game
 * @property-read Student|null $student
 * @property-read Collection<int, GameAnswer> $answers
 */
#[UseFactory(GameRoundFactory::class)]
#[Fillable(['id', 'position', 'payload', 'progress', 'misses', 'solved_at', 'skipped_at', 'points', 'played_on', 'week_start'])]
class GameRound extends Model
{
    /** @use HasFactory<GameRoundFactory> */
    use HasFactory, HasUlids;

    /**
     * @var bool
     */
    public $timestamps = false;

    /**
     * The round as the board and the phone receive it.
     *
     * @param  int  $total  rounds in the game
     */
    public function snapshot(int $total): RoundData
    {
        return RoundData::fromPayload($this->id, $this->position, $total, $this->payload);
    }

    public function progressData(): RoundProgressData
    {
        return new RoundProgressData(
            round_id: $this->id,
            hit_item_ids: $this->progress,
            misses: $this->misses,
            solved: $this->solved_at !== null,
            skipped: $this->skipped_at !== null,
        );
    }

    /**
     * Neither solved nor skipped.
     */
    public function isOpen(): bool
    {
        return $this->solved_at === null && $this->skipped_at === null;
    }

    /**
     * @return BelongsTo<GameSession, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(GameSession::class, 'game_session_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return HasMany<GameAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(GameAnswer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'payload' => 'array',
            'progress' => 'array',
            'misses' => 'integer',
            'solved_at' => 'datetime',
            'skipped_at' => 'datetime',
            'points' => 'integer',
            // `played_on` and `week_start` stay plain `Y-m-d` strings: a date
            // cast would write `Y-m-d 00:00:00`, which SQLite keeps as text,
            // and rankings compare these columns with a date on every engine.
        ];
    }

    /**
     * Rounds neither solved nor skipped.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('solved_at')->whereNull('skipped_at');
    }
}
