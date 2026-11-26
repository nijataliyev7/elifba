<?php

namespace App\Domain\Rooms\Enums;

use Illuminate\Support\Facades\Config;

/**
 * How strong the computer plays a board game (docs/architecture.md §12
 * "The computer's seat", ADR 036 decisions 4–6): «Asan», «Orta», «Çətin»
 * (`rooms.computer.level.*`). It is the computer's own level and never the
 * game's difficulty, which in a board game is its clock (ADR 034 decision
 * 6) and in domino a turn's time (ADR 038 decision 22). What a level means
 * to the search — how far it looks, how many positions it may look at and
 * how far below the best a move it takes may be — is
 * `elifba.rooms.computer.levels`, and in domino the deals it looks at
 * `elifba.rooms.computer.domino`, read here alone.
 */
enum RoomComputerLevel: int
{
    case Easy = 1;
    case Medium = 2;
    case Hard = 3;

    /** The plies the search looks ahead at most. */
    public function depth(): int
    {
        return Config::integer("elifba.rooms.computer.levels.{$this->value}.depth");
    }

    /** The positions the search looks at at most. */
    public function nodes(): int
    {
        return Config::integer("elifba.rooms.computer.levels.{$this->value}.nodes");
    }

    /** How far below the best a move may be and still be taken. */
    public function margin(): int
    {
        return Config::integer("elifba.rooms.computer.levels.{$this->value}.margin");
    }

    /**
     * In domino «101» (ADR 038 decision 55), the deals of the tiles it has
     * not seen that it plays each of its plays out in — none below
     * «Çətin» (`DominoEngine`).
     */
    public function deals(): int
    {
        return Config::integer("elifba.rooms.computer.domino.{$this->value}.deals", 0);
    }
}
