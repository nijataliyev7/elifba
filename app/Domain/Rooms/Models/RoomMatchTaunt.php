<?php

namespace App\Domain\Rooms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A taunt in a board match (docs/architecture.md §12 "Board games", ADR
 * 034 decision 11): a key of `content/rooms/taunts.json` one player threw
 * at the other, when. Free — a match has no points to pay with — and
 * limited by `elifba.rooms.taunts.cooldown_ms`. Written only by
 * `ThrowMatchTaunt`; the target is emptied after
 * `elifba.rooms.taunts.forget_target_hours` (`PruneOldRooms`).
 *
 * @property string $id
 * @property string $event_id
 * @property string $room_match_id
 * @property string $room_player_id the thrower
 * @property string|null $target_player_id
 * @property string $taunt
 * @property int $sent_ms
 */
class RoomMatchTaunt extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent_ms' => 'integer',
        ];
    }
}
