<?php

namespace App\Domain\Rooms\Models;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomStatus;
use App\Domain\Rooms\Policies\RoomPolicy;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomDefaults;
use App\Domain\Rooms\Support\RoomSeats;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\RoomFactory;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use LogicException;

/**
 * A room an account opened (docs/architecture.md §1 `rooms`, §12 "Rooms and
 * joining", ADR 020): the host, a code while it is open, a lock, the
 * revision its signal carries and when it was last active. A room has no
 * name, title or picture; nothing a person typed is stored for it.
 *
 * `{room}` binds only a room the current account hosts or has a row in
 * that the host did not remove (`resolveRouteBinding`), so any other id is
 * a 404 before controller code; `RoomPolicy` is the second layer. A room is
 * open until `closed_at` is set, and an idle one already counts as closed
 * (`isOpen()`) before `CloseIdleRooms` writes it.
 *
 * The `setup_*` columns are the host's choice of the next game (G2, ADR 026
 * decision 8), written only by `OpenRoom` and `ChooseRoomGame` — and the
 * two seats of a board game also by `StartRoomGame` (D3, ADR 034 decisions
 * 7–8) — and read through `setup()`. `taunts` is the host's switch for taunts (T2, ADR
 * 029 decision 12), on by default, written only by `SetRoomTaunts`.
 *
 * `voice_at` and `voice_epoch` are the room's live voice (§12 "Live voice",
 * ADR 031): on since that moment, and the number in its voice room's name
 * (`voiceRoom()`), raised to close that voice room for everyone. Only
 * `SetRoomVoice`, a removal, a close and the sweep write them.
 *
 * @property string $id
 * @property int $user_id the host
 * @property string|null $code six digits from `RoomCode` while open; null once closed
 * @property CarbonImmutable|null $locked_at set: no new player comes in
 * @property int $revision raised by every change the room sees; `room.changed` carries it
 * @property CarbonImmutable $last_activity_at a join, a game started, an answer
 * @property CarbonImmutable|null $closed_at final
 * @property RoomMechanic|null $setup_mechanic the choice's game; null in a room opened before G2
 * @property int|null $setup_difficulty
 * @property int|null $setup_rounds kept for the next race while a board game is chosen
 * @property string|null $setup_white_id the seat that plays white in the next board game (D3); read through `RoomSeats`
 * @property string|null $setup_black_id the seat that plays black
 * @property RoomComputerLevel|null $setup_computer the level of the computer chosen for the next board game (K3, ADR 036); null: none chosen — two players, or the computer by itself in a room of one (`RoomSeats`)
 * @property bool $taunts the host's switch: players may throw taunts
 * @property int|null $screen_seen_ms when the big screen last read the state while shown, Unix ms (`RecordScreenRead`)
 * @property CarbonImmutable|null $voice_at the host turned the room's voice on; null: off
 * @property int $voice_epoch in the voice room's name; raised to close it for everyone
 * @property CarbonImmutable $created_at what the retention period counts from
 * @property CarbonImmutable $updated_at
 * @property-read User $host
 * @property-read Collection<int, RoomPlayer> $players
 * @property-read Collection<int, RoomGame> $games by position
 */
#[UseFactory(RoomFactory::class)]
#[UsePolicy(RoomPolicy::class)]
class Room extends Model
{
    /** @use HasFactory<RoomFactory> */
    use HasFactory, HasUlids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'revision' => 0,
        'taunts' => true,
        'voice_epoch' => 0,
    ];

    /**
     * The moment before which a room's last activity makes it idle.
     */
    public static function idleSince(): CarbonImmutable
    {
        return CarbonImmutable::now()->subHours(Config::integer('elifba.rooms.idle_hours'));
    }

    /**
     * Open: not closed, and active within `elifba.rooms.idle_hours`.
     */
    public function isOpen(): bool
    {
        return $this->closed_at === null && $this->last_activity_at->gt(self::idleSince());
    }

    /** Whether the host has the room's voice on (§12 "Live voice"); the instance's switch is `RoomsArea::voice()`'s. */
    public function voiceIsOn(): bool
    {
        return $this->voice_at !== null;
    }

    /**
     * The voice room's name at the voice server: `{id}.{voice_epoch}`, or
     * with another epoch — the one before a raise (ADR 031 decision 21).
     */
    public function voiceRoom(?int $epoch = null): string
    {
        return $this->id.'.'.($epoch ?? $this->voice_epoch);
    }

    public function status(): RoomStatus
    {
        return $this->isOpen() ? RoomStatus::Open : RoomStatus::Closed;
    }

    /**
     * The room's latest game with its rounds, or null before the first.
     */
    public function latestGame(): ?RoomGame
    {
        return $this->games()->reorder()->orderByDesc('position')->with('rounds')->first();
    }

    /**
     * The game being played at `$nowMs`: the latest, until it is over — a
     * race by its timetable (§12 "The race"), a board game by its outcome
     * (§12 "Board games"), as its driver says. Whether the room is open is
     * the caller's to ask.
     */
    public function runningGame(int $nowMs): ?RoomGame
    {
        $game = $this->latestGame();

        return $game !== null && ! RoomGameDriver::of($game->mechanic)->isOver($game, $nowMs) ? $game : null;
    }

    /**
     * The host's choice of the next game (§12 "Games first", ADR 026
     * decision 8): the one stored, while it is still a level a room plays;
     * else — a room opened before G2 — its latest game's; else the first
     * playable game at the host's defaults (`RoomDefaults`). A board game's
     * choice has the room's two seats (`RoomSeats`, ADR 034 decision 7) —
     * or one and the computer, chosen or, in a room of fewer than two
     * present players, by itself (ADR 036 as amended 2026-11-22).
     * `StartRoomGame` starts it, and the state shows it to every device.
     */
    public function setup(): RoomGameSetupData
    {
        return $this->chosen()->seated(fn (): array => RoomSeats::of($this));
    }

    /**
     * The rounds the room's next race takes when its choice names none — a
     * race chosen after a board game, whose choice has no rounds: the
     * number the room keeps (`setup_rounds`), else a new room's
     * (`RoomDefaults`).
     */
    public function roundsKept(RoomMechanic $race): int
    {
        return $this->setup_rounds ?? RoomDefaults::for($this->host, $race)->rounds ?? throw new LogicException('A race has its rounds.');
    }

    private function chosen(): RoomGameSetupData
    {
        if ($this->setup_mechanic !== null && $this->setup_difficulty !== null) {
            $stored = RoomGameSetupData::of($this->setup_mechanic, $this->setup_difficulty, $this->setup_rounds);

            if ($stored?->isPlayable() === true) {
                return $stored;
            }
        }

        $latest = $this->games()->reorder()->orderByDesc('position')->withCount('rounds')->first();

        if ($latest !== null) {
            $played = RoomGameSetupData::of($latest->mechanic, $latest->difficulty, (int) $latest->getAttribute('rounds_count'));

            if ($played?->isPlayable() === true) {
                return $played;
            }
        }

        return RoomDefaults::for($this->host, $this->setup_mechanic ?? RoomGameSetupData::firstMechanic());
    }

    public function isHostedBy(User $account): bool
    {
        return $this->user_id === $account->id;
    }

    /**
     * The account's row in this room, present or past, unless the host
     * removed it; null for an account that has none (or took its name out).
     */
    public function rowOf(User $account): ?RoomPlayer
    {
        return $this->players()->where('user_id', $account->id)->whereNull('removed_at')->first();
    }

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $account = Auth::user();

        if (! $account instanceof User) {
            return null;
        }

        return static::query()
            ->reachableBy($account)
            ->where($this->qualifyColumn($field ?? $this->getRouteKeyName()), $value)
            ->first();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<RoomPlayer, $this>
     */
    public function players(): HasMany
    {
        return $this->hasMany(RoomPlayer::class);
    }

    /**
     * The games played in the room, one at a time, in order (B3).
     *
     * @return HasMany<RoomGame, $this>
     */
    public function games(): HasMany
    {
        return $this->hasMany(RoomGame::class)->orderBy('position');
    }

    /**
     * `{member}` is scoped under `{room}` through `players()`.
     *
     * @param  string  $childType
     */
    protected function childRouteBindingRelationshipName($childType): string
    {
        return $childType === 'member' ? 'players' : parent::childRouteBindingRelationshipName($childType);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'locked_at' => 'datetime',
            'revision' => 'integer',
            'last_activity_at' => 'datetime',
            'closed_at' => 'datetime',
            'setup_mechanic' => RoomMechanic::class,
            'setup_difficulty' => 'integer',
            'setup_rounds' => 'integer',
            'setup_computer' => RoomComputerLevel::class,
            'taunts' => 'boolean',
            'screen_seen_ms' => 'integer',
            'voice_at' => 'datetime',
            'voice_epoch' => 'integer',
        ];
    }

    /**
     * Rooms that are open: not closed and not idle.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull($query->qualifyColumn('closed_at'))
            ->where($query->qualifyColumn('last_activity_at'), '>', self::idleSince());
    }

    /**
     * Rooms the account hosts or has a row in that the host did not remove.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function reachableBy(Builder $query, User $account): void
    {
        $query->where(static fn (Builder $query) => $query
            ->where($query->qualifyColumn('user_id'), $account->id)
            ->orWhereHas('players', static fn (Builder $players) => $players
                ->where('user_id', $account->id)
                ->whereNull('removed_at')));
    }
}
