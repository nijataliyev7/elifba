<?php

namespace App\Domain\Rooms\Enums;

/**
 * Where a domino game stands at a moment (docs/architecture.md §12
 * "Domino", ADR 038 decisions 24 and 38, the state's `domino.phase`):
 * `countdown` before the first hand opens, `play` while a hand is open,
 * `sheet` in the pause after a hand that did not end the game, `over` once
 * a side reached the target or the game was stopped. Derived by
 * `DominoGame` from the acts and the moment, never stored.
 */
enum RoomDominoPhase: string
{
    case Countdown = 'countdown';
    case Play = 'play';
    case Sheet = 'sheet';
    case Over = 'over';
}
