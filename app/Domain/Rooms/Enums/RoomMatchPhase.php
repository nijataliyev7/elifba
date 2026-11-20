<?php

namespace App\Domain\Rooms\Enums;

/**
 * Where a board game stands at a moment (docs/architecture.md §12 "Board
 * games", ADR 034 decision 17, the state's `match.phase`): `countdown`
 * before its clock starts, `play` while it is live, `over` once it has an
 * outcome. Derived by `DraughtsMatch`, never stored.
 */
enum RoomMatchPhase: string
{
    case Countdown = 'countdown';
    case Play = 'play';
    case Over = 'over';
}
