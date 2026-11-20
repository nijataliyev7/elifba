<?php

namespace Tests\Support;

use App\Domain\Rooms\Support\Checkers;

/**
 * Checkers positions for tests (ADR 034): a board from its pieces, and a
 * square from its row and column (row 0 at the top as white sees it).
 */
final class Boards
{
    /**
     * @param  array<string, list<int>>  $pieces  by piece character
     */
    public static function of(array $pieces): string
    {
        $board = str_repeat(Checkers::EMPTY, 64);

        foreach ($pieces as $piece => $squares) {
            foreach ($squares as $square) {
                $board[$square] = $piece;
            }
        }

        return $board;
    }

    public static function sq(int $row, int $col): int
    {
        return $row * 8 + $col;
    }
}
