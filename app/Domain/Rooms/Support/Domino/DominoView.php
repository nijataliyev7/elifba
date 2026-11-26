<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomTable;

/**
 * What one seat of a domino game has seen of the hand open or last (docs/
 * architecture.md §12 "Domino", ADR 038 decision 54): its own tiles, the
 * line and its ends, how many tiles each seat and the boneyard hold, every
 * play, give, draw and pass of the hand by seat, its own plays in its turn,
 * and the account. Built by `DominoGame::view()`.
 *
 * It has no field for another seat's tile, the boneyard's order or the
 * seed — a draw is a count, a pass a seat — so whatever decides from a
 * view (the computer, `DominoEngine`, from Y5) cannot peek: two games that
 * differ only in tiles the seat has not seen give it equal views
 * (`DominoViewTest`).
 *
 * @phpstan-import-type Placed from DominoRules
 * @phpstan-import-type Play from DominoRules
 * @phpstan-import-type Event from DominoHand
 * @phpstan-import-type Side from DominoLedger
 */
final readonly class DominoView
{
    /**
     * @param  int  $hand  the hand's number; 0 before the first opens
     * @param  int|null  $turn  the seat to move while a hand is played
     * @param  list<DominoTile>  $tiles  the seat's own, in the order dealt and drawn
     * @param  list<Placed>  $line  from the left end to the right
     * @param  array<string, int>  $ends  the number open at each end, by name
     * @param  list<int>  $held  how many tiles each seat holds
     * @param  int  $boneyard  how many tiles the boneyard holds
     * @param  int  $drawable  how many of them may still be drawn (R12, R13)
     * @param  bool  $closed  the boneyard is shut by the line (R13)
     * @param  list<Event>  $events  the hand's plays, gives, draws (a count) and passes, by seat
     * @param  list<Play>  $legal  the seat's plays, in its own turn; none otherwise
     * @param  list<Side>  $sides  the account, in the order of the sides
     */
    public function __construct(
        public int $seat,
        public RoomTable $table,
        public int $hand,
        public ?int $opener,
        public ?RoomOpenerWhy $why,
        public ?int $turn,
        public array $tiles,
        public array $line,
        public array $ends,
        public array $held,
        public int $boneyard,
        public int $drawable,
        public bool $closed,
        public array $events,
        public array $legal,
        public array $sides,
        public int $bank,
    ) {}

    /** The side the seat plays for. */
    public function side(): int
    {
        return $this->table->sideOf($this->seat);
    }
}
