<?php

namespace App\Domain\Play\Models;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Policies\GameSessionPolicy;
use App\Domain\Play\Support\Requester;
use App\Models\Concerns\BelongsToTeacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\GameSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One game a class plays: mechanic × pack × level, with its rounds as
 * snapshots (docs/architecture.md §1, §2). `status` changes only through
 * `TransitionGameSession`; `board_epoch` fences the board allowed to write
 * (§4). The current round is derived (the first open one), never stored.
 * `{game}` binds only the current teacher's games (`BelongsToTeacher`) or,
 * for a guest board's bearer, exactly the game that board shows (§4).
 *
 * @property string $id also the channel name `game.{id}` (§4)
 * @property int $user_id the owner; equals the class's teacher
 * @property string $school_class_id
 * @property Mechanic $mechanic
 * @property string $pack theme pack key
 * @property int $pack_version the pack version the rounds were built from
 * @property string $level level key
 * @property LetterCase $letter_case
 * @property int $seed makes round generation reproducible
 * @property GameSessionStatus $status
 * @property int $board_epoch
 * @property string|null $join_code remote pairing (`IssueJoinCode`, §4)
 * @property CarbonImmutable|null $join_code_expires_at
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property CarbonImmutable $last_activity_at
 * @property-read User $teacher
 * @property-read SchoolClass $schoolClass
 * @property-read Collection<int, GameRound> $rounds
 */
#[UseFactory(GameSessionFactory::class)]
#[UsePolicy(GameSessionPolicy::class)]
#[Fillable(['mechanic', 'pack', 'pack_version', 'level', 'letter_case', 'seed', 'status', 'board_epoch', 'started_at', 'ended_at', 'last_activity_at'])]
class GameSession extends Model
{
    /** @use HasFactory<GameSessionFactory> */
    use BelongsToTeacher, HasFactory, HasUlids {
        BelongsToTeacher::resolveRouteBinding as resolveTeacherRouteBinding;
    }

    /**
     * The table has `started_at`, `ended_at` and `last_activity_at` instead.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function whereTeacher(Builder $query, User $teacher): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $teacher->id);
    }

    public function isOwnedBy(User $teacher): bool
    {
        return $this->user_id === $teacher->id;
    }

    /**
     * A guest board's bearer (`auth:board,web` on the board routes) finds
     * only the game its board shows now; a teacher finds their own games.
     *
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $who = Requester::current();

        if ($who instanceof GuestBoard) {
            return is_string($value) && $who->showsGame($value)
                ? static::query()->whereKey($value)->where('user_id', $who->user_id)->first()
                : null;
        }

        return $this->resolveTeacherRouteBinding($value, $field);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return HasMany<GameRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(GameRound::class)->orderBy('position');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'mechanic' => Mechanic::class,
            'pack_version' => 'integer',
            'letter_case' => LetterCase::class,
            'seed' => 'integer',
            'status' => GameSessionStatus::class,
            'board_epoch' => 'integer',
            'join_code_expires_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'last_activity_at' => 'datetime',
        ];
    }

    /**
     * Live or paused games.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereIn('status', array_map(
            static fn (GameSessionStatus $status): string => $status->value,
            GameSessionStatus::open(),
        ));
    }
}
