<?php

namespace App\Domain\Rooms\Enums;

/**
 * What a seat did in a board game (docs/architecture.md §12 "Board games",
 * ADR 034 decision 9): the kind of a `room_acts` row, the only facts of a
 * match. A device sends the first five; `leave` is written by the server
 * alone, when a seat of a live match leaves the room or is removed.
 *
 * From Y5 (§12 "Domino", ADR 038 decision 16) a domino game's three: `play`
 * — a tile at an end, from the seat to move's device or played by the
 * server for a seat (`PlayComputerMove`) —, `yield` — «Sən aç», the seat
 * with the right gives the opening to its partner — and `back` — «Oyuna
 * qayıt», a seat's player who was away plays its own turns again, from its
 * device or from `JoinRoom` at a return. Drawing and passing are no acts.
 * Which kinds a game takes is its driver's (`RoomGameDriver::act()`).
 */
enum RoomActKind: string
{
    case Move = 'move';
    case Offer = 'offer';
    case Accept = 'accept';
    case Decline = 'decline';
    case Resign = 'resign';
    case Leave = 'leave';
    case Play = 'play';
    case Yield = 'yield';
    case Back = 'back';
}
