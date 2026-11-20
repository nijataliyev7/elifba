<?php

namespace App\Domain\Rooms\Support\Draughts;

use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Enums\RoomMatchColor;

/**
 * One act of a board game as `DraughtsMatch` replays it (docs/architecture.md
 * §12 "Board games", ADR 034 decision 9): a `room_acts` row with its seat
 * read as a side — what was done, by which side, at which ply, a move's
 * squares, and when the server received it.
 */
final readonly class DraughtsAct
{
    /**
     * @param  int  $ply  the moves made before it
     * @param  list<string>|null  $path  a move's start and every landing; null for the other kinds
     */
    public function __construct(
        public RoomActKind $kind,
        public RoomMatchColor $side,
        public int $ply,
        public ?array $path,
        public int $actedMs,
    ) {}
}
