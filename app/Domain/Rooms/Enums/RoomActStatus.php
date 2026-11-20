<?php

namespace App\Domain\Rooms\Enums;

/**
 * What `RecordRoomAct` did with a seat's act (docs/architecture.md §12
 * "Board games", ADR 034 decision 9), the receipt's `status`:
 *
 * - `accepted`: stored now, at the server's moment;
 * - `duplicate`: its `event_id` is already stored;
 * - `late`: the room's game is not a live board game, the account is not
 *   present, or the act does not fit the match as it stands — a move
 *   before the clock starts, out of turn, for another ply or after the
 *   flag; an offer the seat may not make; an answer to no standing offer.
 *   Nothing was written.
 */
enum RoomActStatus: string
{
    case Accepted = 'accepted';
    case Duplicate = 'duplicate';
    case Late = 'late';
}
