<?php

namespace App\Domain\Rooms\Models;

use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\RoomPlayerFactory;
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
 * An account in a room (docs/architecture.md §1 `room_players`, §12 "Rooms
 * and joining", ADR 020). The row holds no name: the room shows the
 * account's `users.name`, read when a page or the state is built, and a
 * row without an account (deleted, or the player took their name out) is
 * «Oyunçu». Its id (`{member}`) is the only id of a player a device ever
 * gets — never the account id.
 *
 * A player is present while the row has an account and has neither left
 * nor been removed. One who left may come back with the code to the same
 * row; one the host removed cannot. `seen_ms` is when the player's own room
 * page last read the state of a running game (`RecordRoomRead`), which
 * tells a round whether to wait for them (`RoomWaits`); a device never
 * gets it.
 *
 * `voice_admitted_at` and `voice_muted_at` are what the host decided about
 * the player's live voice (§12 "Live voice", ADR 031 decisions 8–12): let
 * in, and muted until the host lifts it. The standing they give is
 * derived (`RoomVoice::standingOf()`); a device never gets them.
 *
 * `ready_for` is the position of the game the player said «Hazıram» for
 * between games (§12 "Ready for the next game", ADR 035): written only by
 * `SetRoomPlayerReady`, cleared by `LeaveRoom`. Whether it counts is
 * derived (`RoomReady::counts()`); a device gets only that.
 *
 * @property string $id
 * @property string $room_id
 * @property int|null $user_id null = no account any more, or the name was taken out
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable|null $left_at
 * @property CarbonImmutable|null $removed_at
 * @property int|null $seen_ms Unix ms; null = the page has not read a running game's state
 * @property CarbonImmutable|null $voice_admitted_at let into the room's voice; null: waiting
 * @property CarbonImmutable|null $voice_muted_at muted by the host; null: may speak
 * @property int|null $ready_for the position of the game the player said «Hazıram» for; null: no mark
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Room $room
 * @property-read User|null $user
 * @property-read Collection<int, RoomAnswer> $answers
 */
#[UseFactory(RoomPlayerFactory::class)]
class RoomPlayer extends Model
{
    /** @use HasFactory<RoomPlayerFactory> */
    use HasFactory, HasUlids;

    public function isPresent(): bool
    {
        return $this->user_id !== null && $this->left_at === null && $this->removed_at === null;
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The player's answers, one per round at most (B3).
     *
     * @return HasMany<RoomAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(RoomAnswer::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'removed_at' => 'datetime',
            'seen_ms' => 'integer',
            'voice_admitted_at' => 'datetime',
            'voice_muted_at' => 'datetime',
            'ready_for' => 'integer',
        ];
    }

    /**
     * Players who are in the room now.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function present(Builder $query): void
    {
        $query->whereNotNull($query->qualifyColumn('user_id'))
            ->whereNull($query->qualifyColumn('left_at'))
            ->whereNull($query->qualifyColumn('removed_at'));
    }
}
