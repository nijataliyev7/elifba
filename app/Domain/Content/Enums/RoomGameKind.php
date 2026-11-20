<?php

namespace App\Domain\Content\Enums;

/**
 * The kind of a room game (docs/architecture.md §12 "Board games", ADR 034
 * decision 1): a `race` — everyone answers the same timed rounds at once —
 * or a `board` game, two seats taking turns on one board while the rest of
 * the room watches. It follows from the game's family (`RoomFamily::kind()`,
 * `RoomMechanic::kind()`) and is never stored. In `Rooms` it is read in one
 * place, `Rooms\Support\Drivers\RoomGameDriver::of()`, and by the data
 * classes that hold a shape by kind.
 */
enum RoomGameKind: string
{
    case Race = 'race';
    case Board = 'board';
}
