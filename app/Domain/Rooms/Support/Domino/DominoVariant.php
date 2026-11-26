<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomOpenerWhy;

/**
 * What differs between domino games (docs/architecture.md §12 "Domino",
 * ADR 038 decision 60): who opens a hand and with what, how many tiles a
 * draw takes and how many stay, when the boneyard is shut, when a hand is
 * void, how a hand is counted, the account between hands and the target.
 * The tiles, the tables, the seats and sides, the order of the turns, the
 * deal and R4, the line's two named ends and a play's fit are the core's
 * (`DominoTile`, `DominoDeal`, `DominoRules`, `DominoHand`), the same for
 * every game; nothing in the core asks which game it plays. `Domino101`
 * is the one built.
 *
 * @phpstan-type Count array{winner: int|null, tied: bool, left: list<int>, counts: list<int>, gain: int, bank: int}
 */
interface DominoVariant
{
    /** The balance that ends a game. */
    public function target(): int;

    /** The most tiles one draw takes. */
    public function drawSize(): int;

    /** The tiles of the boneyard that are never drawn. */
    public function reserve(): int;

    /**
     * Who opens a hand dealt so, and why: the seat the hand before gave the
     * right to (`next()`), or the deal's when it gave none.
     *
     * @param  array{0: int|null, 1: RoomOpenerWhy}|null  $right  what the hand before said (`next()`); null for a game's first hand
     * @return array{0: int, 1: RoomOpenerWhy}
     */
    public function opener(DominoDeal $deal, ?array $right): array;

    /**
     * Who opens the hand after this one, as far as this hand tells: a seat,
     * or null while the next deal decides it.
     *
     * @return array{0: int|null, 1: RoomOpenerWhy}
     */
    public function next(DominoHand $before, bool $anyOpen): array;

    /**
     * The tiles the seat to move may open the hand with.
     *
     * @return list<DominoTile>
     */
    public function openings(DominoHand $hand): array;

    /** Whether the seat to move may give the opening to its partner. */
    public function mayGive(DominoHand $hand): bool;

    /** Whether the boneyard is shut by the line: nobody draws, whatever is left. */
    public function closed(DominoHand $hand): bool;

    /** Whether a hand whose first tile was just played is void. */
    public function void(DominoHand $hand): bool;

    /**
     * The pips a seat's tiles count at a hand's end.
     *
     * @param  list<DominoTile>  $tiles
     */
    public function pips(array $tiles): int;

    /**
     * A hand that ended, counted: the winning side or none, whether a
     * blocked hand is tied, each seat's pips left, each side's count, the
     * winner's gain and what goes to the bank.
     *
     * @return Count
     */
    public function count(DominoHand $hand): array;

    /** The account of a game with this many sides, before its first hand. */
    public function ledger(int $sides): DominoLedger;
}
