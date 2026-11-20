<?php

namespace App\Domain\Rooms\Enums;

/**
 * A side of a board game (docs/architecture.md §12 "Board games", ADR 034
 * decisions 4 and 17): its pieces' colour, the seat that plays them, and
 * the state's `turn`, `offer` and `sides[].color`. White moves first
 * (6.1.4.1), so the side to move follows from the number of moves made.
 */
enum RoomMatchColor: string
{
    case White = 'white';
    case Black = 'black';

    /** The side to move after `$ply` moves. */
    public static function toMove(int $ply): self
    {
        return $ply % 2 === 0 ? self::White : self::Black;
    }

    public function other(): self
    {
        return $this === self::White ? self::Black : self::White;
    }
}
