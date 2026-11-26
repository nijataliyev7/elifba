<?php

namespace App\Domain\Rooms\Enums;

/**
 * The table of a domino game (docs/architecture.md §12 "Domino", ADR 038
 * decision 10): `two` («2 nəfər»), `three` («3 nəfər»), `four` («4 nəfər»,
 * each for itself) and `pairs` («Para 2×2», two against two). Seats are
 * numbered from 0 in the order of the turns (R3); a side is what scores —
 * a seat at the first three tables, and at `pairs` seats 0 and 2 against
 * seats 1 and 3, partners facing each other.
 */
enum RoomTable: string
{
    case Two = 'two';
    case Three = 'three';
    case Four = 'four';
    case Pairs = 'pairs';

    /** A deal gives each seat seven tiles (R2). */
    public const int HAND = 7;

    /** How many seats sit at the table. */
    public function seats(): int
    {
        return match ($this) {
            self::Two => 2,
            self::Three => 3,
            self::Four, self::Pairs => 4,
        };
    }

    /** How many sides score. */
    public function sides(): int
    {
        return $this === self::Pairs ? 2 : $this->seats();
    }

    /** The side a seat plays for. */
    public function sideOf(int $seat): int
    {
        return $this === self::Pairs ? $seat % 2 : $seat;
    }

    /**
     * The seats of a side, in the order of the turns.
     *
     * @return list<int>
     */
    public function seatsOf(int $side): array
    {
        return $this === self::Pairs ? [$side, $side + 2] : [$side];
    }

    /** A seat's partner at `pairs`; null at the other tables. */
    public function partnerOf(int $seat): ?int
    {
        return $this === self::Pairs ? ($seat + 2) % 4 : null;
    }

    /** The tiles left to the boneyard after the deal: 14, 7 or none (R2). */
    public function boneyard(): int
    {
        return 28 - self::HAND * $this->seats();
    }
}
