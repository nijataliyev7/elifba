<?php

namespace App\Domain\Rooms\Support;

use InvalidArgumentException;

/**
 * The points of an answer in a room (docs/architecture.md §12 "Scoring and
 * what is kept", ADR 022 decision 1), in PHP only: a right answer gives
 * 10, plus 0–10 for speed, `ceil(10 × (answer_ms − elapsed_ms) ÷
 * answer_ms)` — 20 at once, 10 at the bell; a wrong answer gives 0, and
 * nothing is negative. The time is held to the round's answer time, so a
 * moment before the question counts as at once and one after the bell as
 * at the bell. Not `Play\Support\PointsRule`, which children's games keep.
 *
 * From T2 a taunt costs its thrower `TAUNT_COST` (§12 "Taunts (T2)", ADR
 * 029 decision 1), a constant here and never config, so a change never
 * re-prices a game already played. An answer's points never change for
 * it: `RoomRanking` subtracts the cost of the taunts counted at a close,
 * and `RoomTauntBalance` what a player may still spend.
 */
final class RoomPointsRule
{
    /** A board match won (ADR 034 decision 10): the winner's points in the room's standing. */
    public const int MATCH_WIN = 3;

    /** A board match drawn: each player's points in the room's standing. */
    public const int MATCH_DRAW = 1;

    public const int RIGHT = 10;

    public const int SPEED = 10;

    /** What one taunt costs the player who throws it; the target pays nothing. */
    public const int TAUNT_COST = 1;

    /**
     * @param  int  $elapsedMs  the server's receipt − the moment the question opened
     * @param  int  $answerMs  the round's time to answer
     */
    public static function points(bool $correct, int $elapsedMs, int $answerMs): int
    {
        if ($answerMs < 1) {
            throw new InvalidArgumentException('A round has time to answer.');
        }

        if (! $correct) {
            return 0;
        }

        $left = $answerMs - max(0, min($elapsedMs, $answerMs));

        // ceil(SPEED × left ÷ answer) in whole numbers
        return self::RIGHT + intdiv(self::SPEED * $left + $answerMs - 1, $answerMs);
    }
}
