<?php

namespace App\Domain\Rooms\Enums;

/**
 * How a board game came out (docs/architecture.md §12 "Board games", ADR
 * 034 decision 14): a side won, a draw, or `none` — the host ended the
 * game or the room closed under it, and nobody gets its points. Derived by
 * `DraughtsMatch` from the acts, never stored; the reason is
 * `RoomMatchEnding`.
 */
enum RoomMatchOutcome: string
{
    case White = 'white';
    case Black = 'black';
    case Draw = 'draw';
    case None = 'none';

    public static function won(RoomMatchColor $side): self
    {
        return $side === RoomMatchColor::White ? self::White : self::Black;
    }
}
