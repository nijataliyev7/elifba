<?php

namespace App\Domain\Rooms\Enums;

/**
 * What `BuyRoomLetter` did with a purchase (docs/architecture.md §12
 * "«Anaqram»: a clue, a shuffle and a bought letter (ADR 037)", ADR 037
 * decision 12), the receipt's `status`:
 *
 * - `accepted`: stored now; the buyer's state carries the letter;
 * - `duplicate`: its `event_id` is already stored;
 * - `bought`: the position names a letter this player already bought in
 *   the round — a second tap, a second device;
 * - `late`: the round is not the game's current round in its answer
 *   phase, the room is closed, or the player is not present or has
 *   answered.
 *
 * Every status but `accepted` wrote nothing.
 */
enum RoomLetterStatus: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Bought = 'bought';
    case Late = 'late';
}
