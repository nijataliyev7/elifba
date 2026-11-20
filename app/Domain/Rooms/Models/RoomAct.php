<?php

namespace App\Domain\Rooms\Models;

use App\Domain\Rooms\Enums\RoomActKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a seat did in a board game (docs/architecture.md §1
 * `room_acts`, §12 "Board games", ADR 034 decision 9): a move, a draw
 * offer, its answer, a resignation — written only by `RecordRoomAct`, once
 * per `event_id`, which the device makes — or a `leave`, which the server
 * writes when a seat of a live match leaves or is removed (`BoardDriver`).
 * The acts in their order (`id`) are the only facts of a match: the
 * position, the turn, each side's time, the standing offer and the outcome
 * are replayed from them (`DraughtsMatch`), never stored. A move's `path`
 * is the square it started on and every square it landed on, by name;
 * nothing here is a position, a captured square, a result or text.
 *
 * @property int $id the order of a game's acts
 * @property string|null $event_id ULID made by the device; null for a `leave`
 * @property string $room_game_id
 * @property string $room_player_id the seat that did it
 * @property RoomActKind $kind
 * @property int $ply the moves made before it
 * @property list<string>|null $path a move's squares; null for the other kinds
 * @property int $acted_ms Unix ms, the server's moment of the act
 * @property-read RoomGame $game
 */
class RoomAct extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<RoomGame, $this>
     */
    public function game(): BelongsTo
    {
        return $this->belongsTo(RoomGame::class, 'room_game_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => RoomActKind::class,
            'ply' => 'integer',
            'path' => 'array',
            'acted_ms' => 'integer',
        ];
    }
}
