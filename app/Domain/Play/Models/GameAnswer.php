<?php

namespace App\Domain\Play\Models;

use App\Domain\Classroom\Models\Student;
use App\Domain\Play\Enums\HitSource;
use Carbon\CarbonImmutable;
use Database\Factories\GameAnswerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored hit, judged by the server (docs/architecture.md §1, §4). Only a
 * hit that was applied — `correct` or a miss — is stored; `event_id` is
 * unique, so the same hit is stored once however often it is synced.
 *
 * @property int $id
 * @property string $event_id ULID made by the device that produced the hit
 * @property string $game_round_id
 * @property string|null $student_id at the board when the hit happened
 * @property string $item_id id inside the round
 * @property string $content_key `letter:ə`, `word:alma`… for later statistics
 * @property bool $correct
 * @property HitSource $source
 * @property int $board_epoch
 * @property CarbonImmutable|null $client_at the sender's clock, to the second, for latency diagnostics only
 * @property CarbonImmutable $created_at server time
 * @property-read GameRound $round
 * @property-read Student|null $student
 */
#[UseFactory(GameAnswerFactory::class)]
#[Fillable(['event_id', 'item_id', 'content_key', 'correct', 'source', 'board_epoch', 'client_at'])]
class GameAnswer extends Model
{
    /** @use HasFactory<GameAnswerFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<GameRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(GameRound::class, 'game_round_id');
    }

    /**
     * @return BelongsTo<Student, $this>
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'correct' => 'boolean',
            'source' => HitSource::class,
            'board_epoch' => 'integer',
            'client_at' => 'datetime',
        ];
    }
}
