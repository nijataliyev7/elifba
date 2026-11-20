<?php

namespace App\Domain\Rooms\Enums;

/**
 * What a seat did in a board game (docs/architecture.md §12 "Board games",
 * ADR 034 decision 9): the kind of a `room_acts` row, the only facts of a
 * match. A device sends the first five; `leave` is written by the server
 * alone, when a seat of a live match leaves the room or is removed.
 */
enum RoomActKind: string
{
    case Move = 'move';
    case Offer = 'offer';
    case Accept = 'accept';
    case Decline = 'decline';
    case Resign = 'resign';
    case Leave = 'leave';
}
