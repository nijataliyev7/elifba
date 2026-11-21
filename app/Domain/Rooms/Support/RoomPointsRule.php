<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomLetterPrice;
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
 *
 * From ADR 037 (decision 17) an anagram's answer pays for the letters its
 * player bought in the round: `afterLetters()` keeps the points as they
 * are with none, `intdiv(points, LETTER_DIVISOR)` with one (20 → 10, 13 →
 * 6, 12 → 6) and nothing from `LETTERS_TO_NOTHING` on — constants here,
 * never config, like the taunt's cost. `RecordRoomAnswer` stores the
 * points after them, so `room_answers.points` stays the one place points
 * are, and `letterPrice()` names what the next letter costs as a word.
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

    /** One bought letter leaves an answer one part in this many of its points (ADR 037 decision 17). */
    public const int LETTER_DIVISOR = 2;

    /** From this many bought letters on, an answer keeps nothing. */
    public const int LETTERS_TO_NOTHING = 2;

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

    /**
     * An answer's points after the letters its player bought in the round:
     * as they are with none, `intdiv(points, LETTER_DIVISOR)` with one, 0
     * from `LETTERS_TO_NOTHING` on. A wrong answer is 0 either way.
     */
    public static function afterLetters(int $points, int $bought): int
    {
        return match (true) {
            $bought < 0 => throw new InvalidArgumentException('Nobody buys fewer than no letters.'),
            $bought === 0 => $points,
            $bought < self::LETTERS_TO_NOTHING => intdiv($points, self::LETTER_DIVISOR),
            default => 0,
        };
    }

    /**
     * What the next letter costs a player who bought `$bought` in the
     * round, as the word the page says (`RoomLetterPrice`): `half` before
     * the first, `rest` before the one that leaves nothing, `none` once
     * nothing is left to lose.
     */
    public static function letterPrice(int $bought): RoomLetterPrice
    {
        return match (true) {
            $bought < 0 => throw new InvalidArgumentException('Nobody buys fewer than no letters.'),
            $bought === 0 => RoomLetterPrice::Half,
            $bought < self::LETTERS_TO_NOTHING => RoomLetterPrice::Rest,
            default => RoomLetterPrice::None,
        };
    }
}
