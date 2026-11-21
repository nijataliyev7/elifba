<?php

namespace App\Domain\Rooms\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A letter of an anagram's word one player bought in a round
 * (docs/architecture.md §1 `room_letters`, §12 "«Anaqram»: a clue, a
 * shuffle and a bought letter (ADR 037)", ADR 037 decision 12): written
 * only by `BuyRoomLetter`, stored once per `event_id`, which the device
 * makes, and once per round, buyer and place. It holds the letter's place
 * in the drawn word — the tile is the solution's `ids[position]`, never
 * stored here — and the server's moment (`RoomClock`).
 *
 * How many a player bought in a round prices that player's answer
 * (`RoomPointsRule::afterLetters()`, counted by `RecordRoomAnswer`); the
 * tiles reach only the buyer's own state (`RoomLetters`), and nobody else
 * reads whether anyone bought, in the round or after it.
 *
 * @property string $id
 * @property string $event_id ULID made by the device
 * @property string $room_round_id
 * @property string $room_player_id the buyer
 * @property int $position the letter's place in the drawn word, from 0
 * @property int $bought_ms Unix ms, the server's moment of the purchase
 * @property-read RoomRound $round
 * @property-read RoomPlayer $player
 */
class RoomLetter extends Model
{
    use HasUlids;

    public $timestamps = false;

    /**
     * @return BelongsTo<RoomRound, $this>
     */
    public function round(): BelongsTo
    {
        return $this->belongsTo(RoomRound::class, 'room_round_id');
    }

    /**
     * @return BelongsTo<RoomPlayer, $this>
     */
    public function player(): BelongsTo
    {
        return $this->belongsTo(RoomPlayer::class, 'room_player_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'bought_ms' => 'integer',
        ];
    }
}
