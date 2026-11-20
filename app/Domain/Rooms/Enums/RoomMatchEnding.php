<?php

namespace App\Domain\Rooms\Enums;

/**
 * How a board match ended (ADR 034 decision 6). The result — white, black
 * or a draw — is stored beside it; `Host` has no winner.
 */
enum RoomMatchEnding: string
{
    /** The side to move has no legal move: it lost. */
    case NoMoves = 'no_moves';

    /** A player gave up. */
    case Resign = 'resign';

    /** A player left the room or was removed during the match. */
    case Left = 'left';

    /** The side to move ran out of time: it lost. */
    case Time = 'time';

    /** Fifteen moves each with kings only and no capture: a draw. */
    case Quiet = 'quiet';

    /** The host ended the match: no result. */
    case Host = 'host';
}
