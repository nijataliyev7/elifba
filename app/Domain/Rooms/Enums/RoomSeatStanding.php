<?php

namespace App\Domain\Rooms\Enums;

/**
 * How a seat of a domino game stands (docs/architecture.md §12 "Domino",
 * ADR 038 decisions 26–27, the state's `seats[].standing`). `playing`,
 * `left` and `computer` are the seat's presence, an input of the replay —
 * a present player, a player who left the room or was removed, a seat
 * nobody holds; `away` is derived from the acts: a present player's seat
 * whose last turns were all played for it and that has not come back.
 */
enum RoomSeatStanding: string
{
    case Playing = 'playing';
    case Away = 'away';
    case Left = 'left';
    case Computer = 'computer';
}
