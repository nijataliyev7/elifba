<?php

namespace App\Domain\Rooms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One move of a board match (docs/architecture.md §1 `room_moves`, ADR
 * 034): the squares the piece stood on and landed on, and the squares it
 * captured. Written only by `PlayRoomMove`, idempotent by `event_id`.
 *
 * @property int $id
 * @property string $event_id
 * @property string $room_match_id
 * @property int $ply
 * @property string $room_player_id
 * @property list<int> $path
 * @property list<int> $captured
 * @property int $created_ms
 * @property-read RoomMatch $match
 */
class RoomMove extends Model
{
    public $timestamps = false;

    /**
     * @return BelongsTo<RoomMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(RoomMatch::class, 'room_match_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ply' => 'integer',
            'path' => 'array',
            'captured' => 'array',
            'created_ms' => 'integer',
        ];
    }
}
