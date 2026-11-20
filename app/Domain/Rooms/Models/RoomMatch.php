<?php

namespace App\Domain\Rooms\Models;

use App\Domain\Rooms\Enums\RoomBoardGame;
use App\Domain\Rooms\Enums\RoomMatchEnding;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A board match in a room (docs/architecture.md §1 `room_matches`, §12
 * "Board games", ADR 034): two of the room's players, one with white and
 * one with black, the position after the last move and whose turn it is.
 * Only `StartRoomMatch`, `PlayRoomMove` and `EndRoomMatch` write it.
 *
 * Each side's clock runs only on its turn (ADR 034 decision 9): the side to
 * move has `*_left_ms` less the time since `turn_since_ms`. When that runs
 * out the side has lost on time — derived, never written by a job: every
 * reader takes the match through `settled()`, which fills the end in
 * memory, and the next writer saves it with whatever it writes.
 *
 * @property string $id
 * @property string $room_id
 * @property int $position order in the room, 0-based
 * @property RoomBoardGame $board_game
 * @property string $white_player_id
 * @property string $black_player_id
 * @property string $board 64 characters (`Checkers`)
 * @property 'w'|'b' $turn the side to move
 * @property int $ply moves made
 * @property int $quiet_plies kings' moves without a capture in a row (the draw rule)
 * @property list<int>|null $last_path the last move, for the board to mark
 * @property int $started_ms
 * @property int|null $ended_ms
 * @property 'w'|'b'|'draw'|null $result
 * @property RoomMatchEnding|null $ending
 * @property int $white_left_ms white's time left when its turn last began
 * @property int $black_left_ms black's time left when its turn last began
 * @property int $turn_since_ms when the side to move began its turn
 * @property CarbonImmutable $created_at
 * @property-read Room $room
 * @property-read Collection<int, RoomMove> $moves by ply
 */
class RoomMatch extends Model
{
    use HasUlids;

    public const UPDATED_AT = null;

    /** Kings' moves without a capture, both sides together, that make a draw: 15 each. */
    public const int QUIET_DRAW = 30;

    public function isOver(): bool
    {
        return $this->ended_ms !== null;
    }

    /**
     * The moment the side to move runs out of time.
     */
    public function flagMs(): int
    {
        return $this->turn_since_ms + ($this->turn === 'w' ? $this->white_left_ms : $this->black_left_ms);
    }

    /**
     * A side's time left at `$nowMs`: its clock runs only on its turn, and
     * stops when the match ends.
     *
     * @param  'w'|'b'  $side
     */
    public function leftMs(string $side, int $nowMs): int
    {
        $left = $side === 'w' ? $this->white_left_ms : $this->black_left_ms;

        if ($side !== $this->turn) {
            return $left;
        }

        // The side to move's clock stops when the match ends.
        $until = $this->ended_ms === null ? $nowMs : min($nowMs, $this->ended_ms);

        return max(0, $left - max(0, $until - $this->turn_since_ms));
    }

    /**
     * The match as it stands at `$nowMs`: a running match whose side to
     * move ran out of time is over — ended at that moment, the other side
     * winning. Filled in memory only; a writer saves it.
     */
    public function settled(int $nowMs): static
    {
        if (! $this->isOver() && $nowMs >= $this->flagMs()) {
            $this->ended_ms = $this->flagMs();
            $this->result = $this->turn === 'w' ? 'b' : 'w';
            $this->ending = RoomMatchEnding::Time;
        }

        return $this;
    }

    /**
     * The side a room player has in this match, or null for a watcher.
     *
     * @return 'w'|'b'|null
     */
    public function sideOf(?string $playerId): ?string
    {
        return match ($playerId) {
            null => null,
            $this->white_player_id => 'w',
            $this->black_player_id => 'b',
            default => null,
        };
    }

    /**
     * The room player of a side.
     *
     * @param  'w'|'b'  $side
     */
    public function playerOf(string $side): string
    {
        return $side === 'w' ? $this->white_player_id : $this->black_player_id;
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    /**
     * @return HasMany<RoomMove, $this>
     */
    public function moves(): HasMany
    {
        return $this->hasMany(RoomMove::class)->orderBy('ply');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'board_game' => RoomBoardGame::class,
            'ply' => 'integer',
            'quiet_plies' => 'integer',
            'last_path' => 'array',
            'started_ms' => 'integer',
            'ended_ms' => 'integer',
            'ending' => RoomMatchEnding::class,
            'white_left_ms' => 'integer',
            'black_left_ms' => 'integer',
            'turn_since_ms' => 'integer',
        ];
    }
}
