<?php

namespace App\Domain\Rooms\Models;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Rooms\Support\RoomTimetable;
use Carbon\CarbonImmutable;
use Database\Factories\RoomGameFactory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One game played in a room (docs/architecture.md §1 `room_games`, §12
 * "The race", "Room games", ADRs 021 and 023): a mechanic at a difficulty
 * — the pair is the level of `content/rooms/levels.json` — with rounds
 * built from `seed` (`RoomSeed`, never serialized) and the room content
 * of `content_version` when it started. A room plays several, one at a time, by `position`.
 *
 * Only facts are stored: when round 0 opens (`started_ms`), how long a
 * reveal lasts in this game (`reveal_ms`, copied from the config at the
 * start) and the host's end (`ended_ms`). The round and phase at a moment
 * are `RoomTimetable`'s, never stored. Moments are Unix milliseconds.
 *
 * A board game (D3, ADR 034) is a row of the same table: its mechanic's
 * kind says so (`RoomGameDriver`). It has no rounds, no seed and no
 * reveal; it has its two seats and its clock, copied from the room's
 * choice at the start, and its acts (`room_acts`). `started_ms` is when
 * its clock starts. Whether it is over, and how, is replayed from the acts
 * (`DraughtsMatch`), never stored.
 *
 * @property string $id
 * @property string $room_id
 * @property int $position order in the room, 0-based
 * @property RoomMechanic $mechanic
 * @property int $difficulty 1–4
 * @property int $content_version
 * @property int $seed
 * @property int $reveal_ms
 * @property int $started_ms when round 0 opens
 * @property int|null $ended_ms the host ended the game, or the room closed under it
 * @property string|null $white_player_id a board game's seat that plays white
 * @property string|null $black_player_id a board game's seat that plays black
 * @property int|null $clock_ms a board game's: each side's time
 * @property int|null $increment_ms a board game's: what a side gains with each move
 * @property CarbonImmutable $created_at
 * @property-read Room $room
 * @property-read Collection<int, RoomRound> $rounds by position
 * @property-read Collection<int, RoomAct> $acts a board game's, in the order received
 */
#[UseFactory(RoomGameFactory::class)]
class RoomGame extends Model
{
    /** @use HasFactory<RoomGameFactory> */
    use HasFactory, HasUlids;

    public const UPDATED_AT = null;

    /**
     * The largest seed, the same on every engine (a signed 32-bit integer).
     */
    public const int MAX_SEED = 2_147_483_647;

    /**
     * The seed tells every round; it never leaves the server (`RoomSeed`).
     *
     * @var list<string>
     */
    protected $hidden = ['seed'];

    /**
     * The game's timetable, from its rounds as they are loaded.
     */
    public function timetable(): RoomTimetable
    {
        return RoomTimetable::of($this);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<RoomRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(RoomRound::class)->orderBy('position');
    }

    /**
     * A board game's acts, in the order they were received.
     *
     * @return HasMany<RoomAct, $this>
     */
    public function acts(): HasMany
    {
        return $this->hasMany(RoomAct::class)->orderBy('id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'mechanic' => RoomMechanic::class,
            'difficulty' => 'integer',
            'content_version' => 'integer',
            'seed' => 'integer',
            'reveal_ms' => 'integer',
            'started_ms' => 'integer',
            'ended_ms' => 'integer',
            'clock_ms' => 'integer',
            'increment_ms' => 'integer',
        ];
    }
}
