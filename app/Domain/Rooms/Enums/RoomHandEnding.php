<?php

namespace App\Domain\Rooms\Enums;

/**
 * How a hand of domino ended (docs/architecture.md §12 "Domino", ADR 038
 * R5, R15, R18 and R19, the state's `sheet.ending`). Derived by the
 * replay, never stored.
 */
enum RoomHandEnding: string
{
    /** A seat played its last tile: its side wins the others' pips (R15, R17). */
    case Finished = 'finished';

    /** No seat can play and none may draw; one side has the lowest count and wins (R15, R18). */
    case Blocked = 'blocked';

    /** Blocked, with the lowest count shared: nobody wins and every seat's pips go to the bank (R19). */
    case Tied = 'tied';

    /** At `pairs`, neither opponent could answer the first tile: nothing is counted and the tiles are dealt again (R5). */
    case Void = 'void';
}
