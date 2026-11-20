<?php

namespace App\Domain\Rooms\Enums;

/**
 * The board games a room may be opened for (docs/architecture.md §12
 * "Board games", ADR 034): two players, turn by turn, on one board, while
 * the rest of the room watches. Separate from `RoomMechanic`: a board game
 * has no rounds, levels or difficulty, and its rules are code
 * (`Rooms\Support\Checkers`).
 */
enum RoomBoardGame: string
{
    /** «Şaşki»: Russian draughts on an 8 × 8 board. */
    case Checkers = 'checkers';
}
