<?php

namespace App\Domain\Play\Support;

use InvalidArgumentException;

/**
 * Points of a solved round (docs/architecture.md §5, ADR 005): 3 with no
 * wrong hit, 2 with one, 1 with more. Never negative; a skipped round gets
 * nothing. The rule exists only here — the board shows the number the
 * server returns.
 */
final class PointsRule
{
    public const int MAX = 3;

    /**
     * @param  int  $misses  wrong hits in the round before it was solved
     *
     * @throws InvalidArgumentException when misses is negative
     */
    public static function for(int $misses): int
    {
        return match (true) {
            $misses < 0 => throw new InvalidArgumentException('Misses cannot be negative.'),
            $misses === 0 => 3,
            $misses === 1 => 2,
            default => 1,
        };
    }
}
