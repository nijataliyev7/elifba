<?php

namespace App\Domain\Rooms\Support;

/**
 * The rules of Russian draughts (docs/architecture.md §12 "Board games",
 * ADR 034 decision 4), the one judge of a checkers match: PHP only, never
 * mirrored in TypeScript — a page is sent the legal moves and picks one.
 *
 * The board is 64 characters, square `row * 8 + col`, row 0 at the top as
 * white sees it: `.` empty, `w`/`b` a man, `W`/`B` a king. Only the dark
 * squares, `(row + col)` odd, are played. White starts on rows 5–7 and
 * moves first, upwards; black on rows 0–2.
 *
 * - A man steps one square diagonally forward and captures forward and
 *   backward; a king flies along a diagonal and captures a piece any
 *   distance away, landing on any empty square beyond it.
 * - Capturing is compulsory, and a capture continues while it can: a move
 *   is the whole path. Any capturing path may be chosen, not only the
 *   longest. A king that has several landings after a capture must take
 *   one from which it can go on capturing, when there is one.
 * - A captured piece stays on the board until the move ends: it is never
 *   jumped twice and it blocks the way (the «Turkish strike» rule).
 * - A man that reaches the far row becomes a king at once, and a capture
 *   it is making goes on as a king's.
 *
 * A move is a list of squares: where the piece stands, then every square
 * it lands on.
 */
final class Checkers
{
    public const string EMPTY = '.';

    private const array DIRECTIONS = [[-1, -1], [-1, 1], [1, -1], [1, 1]];

    /**
     * The starting position.
     */
    public static function initial(): string
    {
        $board = '';

        for ($row = 0; $row < 8; $row++) {
            for ($col = 0; $col < 8; $col++) {
                $board .= match (true) {
                    ($row + $col) % 2 === 0 => self::EMPTY,
                    $row < 3 => 'b',
                    $row > 4 => 'w',
                    default => self::EMPTY,
                };
            }
        }

        return $board;
    }

    /**
     * The side a piece belongs to, or null for an empty square.
     *
     * @return 'w'|'b'|null
     */
    public static function sideOf(string $piece): ?string
    {
        return match ($piece) {
            'w', 'W' => 'w',
            'b', 'B' => 'b',
            default => null,
        };
    }

    /**
     * @param  'w'|'b'  $side
     * @return 'w'|'b'
     */
    public static function other(string $side): string
    {
        return $side === 'w' ? 'b' : 'w';
    }

    /**
     * Every legal move of a side: its captures when it has any, else its
     * plain moves.
     *
     * @param  'w'|'b'  $side
     * @return list<array{path: list<int>, captured: list<int>}>
     */
    public static function moves(string $board, string $side): array
    {
        $captures = [];
        $steps = [];

        for ($square = 0; $square < 64; $square++) {
            if (self::sideOf($board[$square]) !== $side) {
                continue;
            }

            $king = ctype_upper($board[$square]);
            $lifted = $board;
            $lifted[$square] = self::EMPTY;

            foreach (self::captures($lifted, $side, $square, $king, [], [$square]) as $capture) {
                $captures[] = $capture;
            }

            if ($captures === []) {
                foreach (self::steps($board, $side, $square, $king) as $step) {
                    $steps[] = $step;
                }
            }
        }

        return $captures !== [] ? $captures : $steps;
    }

    /**
     * The legal move a path names, or null when it names none.
     *
     * @param  'w'|'b'  $side
     * @param  list<int>  $path
     * @return array{path: list<int>, captured: list<int>}|null
     */
    public static function find(string $board, string $side, array $path): ?array
    {
        foreach (self::moves($board, $side) as $move) {
            if ($move['path'] === $path) {
                return $move;
            }
        }

        return null;
    }

    /**
     * The board after a legal move: the piece on its last square — a king
     * when it reached the far row on the way — and the captured pieces gone.
     *
     * @param  array{path: list<int>, captured: list<int>}  $move
     */
    public static function apply(string $board, array $move): string
    {
        $from = $move['path'][0];
        $to = $move['path'][count($move['path']) - 1] ?? $from;
        $piece = $board[$from];
        $side = self::sideOf($piece);

        foreach ($move['path'] as $square) {
            if (self::isFarRow($side, $square)) {
                $piece = strtoupper($piece);
            }
        }

        $board[$from] = self::EMPTY;

        foreach ($move['captured'] as $square) {
            $board[$square] = self::EMPTY;
        }

        $board[$to] = $piece;

        return $board;
    }

    /**
     * Whether a move is quiet for the draw rule: a king's move that
     * captures nothing.
     *
     * @param  array{path: list<int>, captured: list<int>}  $move
     */
    public static function isQuiet(string $before, array $move): bool
    {
        return $move['captured'] === [] && ctype_upper($before[$move['path'][0]]);
    }

    /**
     * The plain moves of one piece.
     *
     * @param  'w'|'b'  $side
     * @return list<array{path: list<int>, captured: list<int>}>
     */
    private static function steps(string $board, string $side, int $square, bool $king): array
    {
        $moves = [];
        [$row, $col] = [intdiv($square, 8), $square % 8];
        $forward = $side === 'w' ? -1 : 1;

        foreach (self::DIRECTIONS as [$dr, $dc]) {
            if (! $king && $dr !== $forward) {
                continue;
            }

            for ($r = $row + $dr, $c = $col + $dc; self::inside($r, $c); $r += $dr, $c += $dc) {
                if ($board[$r * 8 + $c] !== self::EMPTY) {
                    break;
                }

                $moves[] = ['path' => [$square, $r * 8 + $c], 'captured' => []];

                if (! $king) {
                    break;
                }
            }
        }

        return $moves;
    }

    /**
     * Every capturing path from a square, the piece lifted from its start.
     *
     * @param  'w'|'b'  $side
     * @param  list<int>  $taken  pieces captured so far on this path
     * @param  list<int>  $path
     * @return list<array{path: list<int>, captured: list<int>}>
     */
    private static function captures(string $board, string $side, int $square, bool $king, array $taken, array $path): array
    {
        $found = [];
        [$row, $col] = [intdiv($square, 8), $square % 8];

        foreach (self::DIRECTIONS as [$dr, $dc]) {
            $r = $row + $dr;
            $c = $col + $dc;

            // A king flies over empty squares up to the piece it captures.
            if ($king) {
                while (self::inside($r, $c) && $board[$r * 8 + $c] === self::EMPTY) {
                    $r += $dr;
                    $c += $dc;
                }
            }

            if (! self::inside($r, $c)) {
                continue;
            }

            $victim = $r * 8 + $c;

            if (self::sideOf($board[$victim]) !== self::other($side) || in_array($victim, $taken, true)) {
                continue;
            }

            $landings = [];

            for ($lr = $r + $dr, $lc = $c + $dc; self::inside($lr, $lc); $lr += $dr, $lc += $dc) {
                if ($board[$lr * 8 + $lc] !== self::EMPTY) {
                    break;
                }

                $landings[] = $lr * 8 + $lc;

                if (! $king) {
                    break;
                }
            }

            $continued = [];
            $ends = [];

            foreach ($landings as $landing) {
                $crowned = $king || self::isFarRow($side, $landing);
                $next = self::captures($board, $side, $landing, $crowned, [...$taken, $victim], [...$path, $landing]);

                if ($next !== []) {
                    array_push($continued, ...$next);
                } else {
                    $ends[] = ['path' => [...$path, $landing], 'captured' => [...$taken, $victim]];
                }
            }

            // After a capture the piece goes on capturing where it can.
            array_push($found, ...($continued !== [] ? $continued : $ends));
        }

        return $found;
    }

    /**
     * @param  'w'|'b'|null  $side
     */
    private static function isFarRow(?string $side, int $square): bool
    {
        $row = intdiv($square, 8);

        return ($side === 'w' && $row === 0) || ($side === 'b' && $row === 7);
    }

    private static function inside(int $row, int $col): bool
    {
        return $row >= 0 && $row < 8 && $col >= 0 && $col < 8;
    }
}
