<?php

namespace App\Domain\Rooms\Enums;

/**
 * Why a board game ended (docs/architecture.md §12 "Board games", ADR 034
 * decision 14), beside its `RoomMatchOutcome`. Derived by `DraughtsMatch`
 * from the acts, never stored; the articles are those of the official
 * rules (ADR 034 decision 4).
 */
enum RoomMatchEnding: string
{
    /** After a move the side to move has no piece or no legal move: the mover wins (6.1.7). */
    case NoMoves = 'no_moves';

    /** A seat resigned: the other side wins (6.1.7). */
    case Resigned = 'resigned';

    /** A seat left the room or was removed: the other side wins (5.4.2.9, by analogy). */
    case Left = 'left';

    /** The side to move ran out of time: the other side wins (5.4.2.6). */
    case Time = 'time';

    /** A draw offered and accepted (5.4.13.1). */
    case Agreed = 'agreed';

    /** The same position with the same side to move for the third time: a draw (5.4.13.1–2). */
    case Repetition = 'repetition';

    /** Three kings or more against a lone king, not taken by the 15th move: a draw (6.1.8.3–4). */
    case ThreeKings = 'three_kings';

    /** Three pieces off the main road against a lone king on it, not taken by the 5th move: a draw (6.1.8.5). */
    case MainRoad = 'main_road';

    /** Both sides with kings and the balance unchanged for 5, 30 or 60 moves: a draw (6.1.8.6). */
    case Balance = 'balance';

    /** Only kings moved, without a capture, for 15 or 25 moves: a draw (6.1.8.8). */
    case KingsOnly = 'kings_only';

    /** The host ended the game or the room closed under it: no result. */
    case Stopped = 'stopped';

    /** Whether the ending is a draw's: agreed, or declared by the server (decision 16). */
    public function draws(): bool
    {
        return match ($this) {
            self::Agreed, self::Repetition, self::ThreeKings, self::MainRoad, self::Balance, self::KingsOnly => true,
            self::NoMoves, self::Resigned, self::Left, self::Time, self::Stopped => false,
        };
    }
}
