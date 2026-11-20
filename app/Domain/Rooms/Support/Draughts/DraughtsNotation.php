<?php

namespace App\Domain\Rooms\Support\Draughts;

use InvalidArgumentException;

/**
 * The official notation of Russian draughts (docs/architecture.md §12
 * "Board games", ADR 034 decisions 5 and 28; 6.1.6): the dark squares'
 * names `a1`–`h8` — the files `a`–`h` from white's left, the ranks 1–8
 * from white's side — and a move's line in the list of moves.
 *
 * A square is its name everywhere outside the rules: in a stored act, the
 * state, a fixture and a test. This class alone turns a name into a place
 * of `DraughtsPosition` and back.
 */
final class DraughtsNotation
{
    /** Whether a string names a dark square. */
    public static function isSquare(string $name): bool
    {
        return preg_match('/^[a-h][1-8]$/', $name) === 1
            && (ord($name[0]) - ord('a') + (int) $name[1] - 1) % 2 === 0;
    }

    public static function place(string $square): int
    {
        if (! self::isSquare($square)) {
            throw new InvalidArgumentException('Not a dark square of the board.');
        }

        return ((int) $square[1] - 1) * 8 + ord($square[0]) - ord('a');
    }

    public static function square(int $place): string
    {
        if ($place < 0 || $place > 63 || ! DraughtsPosition::isDark($place)) {
            throw new InvalidArgumentException('Not a dark square of the board.');
        }

        return chr(ord('a') + DraughtsPosition::file($place)).(DraughtsPosition::rank($place) + 1);
    }

    /**
     * A move's line: `c3-d4` for a quiet move, `c3:e5` for a capture, and
     * every square a capture landed on, `c3:e5:g7` — the official form
     * that shows the way (6.1.6.2), always, so a line is the path a move
     * travels as. Crowning has no mark.
     */
    public static function text(DraughtsMove $move): string
    {
        return implode($move->isCapture() ? ':' : '-', $move->path);
    }
}
