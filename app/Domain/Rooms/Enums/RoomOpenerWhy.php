<?php

namespace App\Domain\Rooms\Enums;

/**
 * Why a seat opens a hand of domino (docs/architecture.md §12 "Domino",
 * ADR 038 decision 38's `OpenerWhy`, the state's `hand.why` and
 * `sheet.next.why`). Derived by the replay, never stored.
 */
enum RoomOpenerWhy: string
{
    /** No account is open: the seat holds the lowest of `1-1` … `6-6` and opens with it (R6). */
    case LowestDouble = 'lowest_double';

    /** No account is open and no seat holds one of those doubles: the seat holds the lowest tile and opens with it (R7). */
    case LowestTile = 'lowest_tile';

    /** The seat finished the hand before (R8a). */
    case Finished = 'finished';

    /** The seat was left with the fewest pips in the blocked hand before (R8b). */
    case Fewest = 'fewest';

    /** The seat made the last play of the tied hand before (R8c). */
    case Blocker = 'blocker';

    /** The hand before was void: the seat that had the right has it again (R8d). */
    case Again = 'again';

    /** The seat's partner gave it the opening (R9). */
    case Given = 'given';

    /** Whether the seat opens by the right of R8 — with any tile it holds. */
    public function byRight(): bool
    {
        return match ($this) {
            self::Finished, self::Fewest, self::Blocker, self::Again, self::Given => true,
            self::LowestDouble, self::LowestTile => false,
        };
    }
}
