<?php

namespace App\Domain\Rooms\Support\Draughts;

use App\Domain\Rooms\Enums\RoomMatchColor;
use Random\Randomizer;

/**
 * The computer's choice of a move of Russian draughts (docs/architecture.md
 * §12 "The computer's seat", ADR 036 decisions 4–7). It judges nothing:
 * the move it returns is one of `DraughtsRules::moves()` — the one judge's
 * own list — and what it looks at below that list is its own business, a
 * faster copy of the same rules on the same board that
 * `DraughtsEngineTest` holds to the judge's answers on the oracle's
 * fixtures.
 *
 * The search is a negamax with alpha–beta over the position's `board`:
 * it deepens one ply at a time up to `$depth`, goes on through every
 * compulsory capture before it counts (so a position is weighed only when
 * nobody must take), does not count a ply for a side with one move, and
 * remembers the positions it met. It stops at `$nodes` positions — the
 * same number on every machine, so the same position, limits and random
 * source give the same move — and keeps the deepest answer it finished;
 * `$maxMs` is only a guard for a machine too slow for the number.
 *
 * A position is weighed by its pieces — a man 100, a king 300 — with a
 * little for a man's advance, the centre files and the first row kept,
 * and for fewer pieces on the board when ahead. The draws of ADR 034
 * decision 16 are not searched: the server declares them.
 *
 * Among the moves whose value is within `$margin` of the best it takes
 * one at random: a wide margin is a weak player that gives pieces away, a
 * narrow one only varies equal games.
 */
final class DraughtsEngine
{
    /** A side without a move has lost; a value past `FORCED` is a forced end. */
    private const int WON = 100_000;

    private const int FORCED = 99_000;

    private const int UNSEEN = 1_000_000;

    /** No line is followed further than this, whatever is compulsory. */
    private const int DEEPEST = 64;

    /** The positions remembered at most; beyond it the memory starts again. */
    private const int REMEMBERED = 120_000;

    /** The dark squares, as places of `DraughtsPosition`. */
    private const array DARK = [
        0, 2, 4, 6, 9, 11, 13, 15, 16, 18, 20, 22, 25, 27, 29, 31,
        32, 34, 36, 38, 41, 43, 45, 47, 48, 50, 52, 54, 57, 59, 61, 63,
    ];

    /** The four diagonals as [file, rank] steps; a white man goes forward along 1 and 3. */
    private const array DIRECTIONS = [[-1, -1], [-1, 1], [1, -1], [1, 1]];

    /** @var list<array<int, int>>|null the next place along each diagonal, or -1 off the board */
    private static ?array $next = null;

    /** @var array{w: array<int, int>, b: array<int, int>, W: array<int, int>, B: array<int, int>}|null */
    private static ?array $worth = null;

    private int $seen = 0;

    private bool $stopped = false;

    /** @var array<string, int> a position met: its depth, value, bound and best move, packed */
    private array $met = [];

    private function __construct(
        private readonly int $nodes,
        private readonly int|float $deadline,
    ) {}

    /**
     * The move the computer makes in a position, or null when the side to
     * move has none.
     *
     * @param  int  $depth  the plies it looks ahead at most, compulsory captures not counted
     * @param  int  $nodes  the positions it looks at at most
     * @param  int  $margin  how far below the best a move may be and still be taken
     */
    public static function choose(DraughtsPosition $position, int $depth, int $nodes, int $margin, Randomizer $random, int $maxMs = 3_000): ?DraughtsMove
    {
        $moves = DraughtsRules::moves($position);

        if (count($moves) < 2) {
            return $moves[0] ?? null;
        }

        self::$next ??= self::diagonals();
        self::$worth ??= self::worths();

        $white = $position->turn === RoomMatchColor::White;
        $after = array_map(static fn (DraughtsMove $move): string => DraughtsRules::apply($position, $move)->board, $moves);
        $engine = new self($nodes, hrtime(true) + $maxMs * 1_000_000);
        $order = array_keys($moves);
        $values = array_fill(0, count($moves), 0);
        $best = 0;

        for ($deep = 1; $deep <= $depth; $deep++) {
            $found = [];
            $top = -self::UNSEEN;

            foreach ($order as $index) {
                // A move further than the margin below the best need not be valued exactly.
                $floor = $top === -self::UNSEEN ? -self::UNSEEN : $top - $margin - 1;
                $value = -$engine->search($after[$index], ! $white, $deep - 1, -self::UNSEEN, -$floor, 1);

                if ($engine->stopped) {
                    break 2;
                }

                $found[$index] = $value;
                $top = max($top, $value);
            }

            $values = $found;
            $best = $top;
            usort($order, static fn (int $a, int $b): int => [$values[$b], $a] <=> [$values[$a], $b]);

            if (abs($best) >= self::FORCED) {
                break;
            }
        }

        $taken = array_values(array_filter(array_keys($moves), static fn (int $index): bool => $values[$index] >= $best - $margin));

        return $moves[$taken[$random->getInt(0, count($taken) - 1)]];
    }

    /**
     * Every move of a side as the search sees it — the start, the last
     * square, the places taken and whether the piece is a king at the end
     * — in the judge's own order. For `DraughtsEngineTest` alone: the rules
     * are `DraughtsRules`.
     *
     * @return list<array{0: int, 1: int, 2: list<int>, 3: bool}>
     */
    public static function seen(DraughtsPosition $position): array
    {
        self::$next ??= self::diagonals();

        return self::moves($position->board, $position->turn === RoomMatchColor::White);
    }

    /** The value of a position for the side to move, `$depth` plies ahead. */
    private function search(string $board, bool $white, int $depth, int $alpha, int $beta, int $ply): int
    {
        if (++$this->seen > $this->nodes || (($this->seen & 2047) === 0 && hrtime(true) > $this->deadline)) {
            $this->stopped = true;

            return 0;
        }

        $moves = self::moves($board, $white);

        if ($moves === []) {
            return $ply - self::WON;
        }

        // A position is weighed only when nobody must take.
        if (($depth <= 0 && $moves[0][2] === []) || $ply >= self::DEEPEST) {
            return $white ? self::weigh($board) : -self::weigh($board);
        }

        $key = null;

        if ($depth > 0) {
            $key = ($white ? 'w' : 'b').$board;
            $known = $this->met[$key] ?? null;

            if ($known !== null) {
                $value = ($known >> 22) - 131_072;
                $bound = ($known >> 8) & 3;

                if (($known & 255) >= $depth) {
                    if ($bound === 0) {
                        return $value;
                    }

                    if ($bound === 1) {
                        $alpha = max($alpha, $value);
                    } else {
                        $beta = min($beta, $value);
                    }

                    if ($alpha >= $beta) {
                        return $value;
                    }
                }

                // The move that was best there is tried first.
                $first = ($known >> 10) & 4095;

                foreach ($moves as $index => $move) {
                    if ($index > 0 && $first === $move[0] * 64 + $move[1]) {
                        $moves[$index] = $moves[0];
                        $moves[0] = $move;

                        break;
                    }
                }
            }
        }

        // A side with one move loses no ply of the look ahead.
        $deeper = count($moves) === 1 ? $depth : $depth - 1;
        $crowned = $white ? 'W' : 'B';
        $floor = $alpha;
        $best = -self::UNSEEN;
        $bestMove = 0;

        foreach ($moves as [$from, $to, $taken, $king]) {
            $child = $board;
            $piece = $child[$from];
            $child[$from] = '.';

            foreach ($taken as $place) {
                $child[$place] = '.';
            }

            $child[$to] = $king ? $crowned : $piece;
            $value = -$this->search($child, ! $white, $deeper, -$beta, -$alpha, $ply + 1);

            if ($this->stopped) {
                return 0;
            }

            if ($value > $best) {
                $best = $value;
                $bestMove = $from * 64 + $to;

                if ($value > $alpha) {
                    $alpha = $value;

                    if ($alpha >= $beta) {
                        break;
                    }
                }
            }
        }

        if ($key !== null && abs($best) < self::FORCED) {
            if (count($this->met) >= self::REMEMBERED) {
                $this->met = [];
            }

            $bound = $best <= $floor ? 2 : ($best >= $beta ? 1 : 0);
            $this->met[$key] = (($best + 131_072) << 22) | ($bestMove << 10) | ($bound << 8) | min($depth, 255);
        }

        return $best;
    }

    /**
     * The moves of a side on a board: its captures when it has any, else
     * its quiet moves — `DraughtsRules::moves()` on places, without the
     * names.
     *
     * @return list<array{0: int, 1: int, 2: list<int>, 3: bool}>
     */
    private static function moves(string $board, bool $white): array
    {
        [$man, $king, $foeMan, $foeKing] = $white ? ['w', 'W', 'b', 'B'] : ['b', 'B', 'w', 'W'];
        $next = self::$next ?? [];
        $captures = [];
        $own = [];

        foreach (self::DARK as $place) {
            $piece = $board[$place];

            if ($piece !== $man && $piece !== $king) {
                continue;
            }

            $own[] = $place;
            $lifted = $board;
            $lifted[$place] = '.';
            self::captures($next, $lifted, $foeMan, $foeKing, $white, $place, $place, $piece === $king, [], $captures);
        }

        if ($captures !== []) {
            return $captures;
        }

        $steps = [];

        foreach ($own as $place) {
            if ($board[$place] === $king) {
                foreach ($next as $along) {
                    for ($to = $along[$place]; $to >= 0 && $board[$to] === '.'; $to = $along[$to]) {
                        $steps[] = [$place, $to, [], true];
                    }
                }

                continue;
            }

            foreach ($white ? [1, 3] : [0, 2] as $direction) {
                $to = $next[$direction][$place];

                if ($to >= 0 && $board[$to] === '.') {
                    $steps[] = [$place, $to, [], $white ? $to >= 56 : $to < 8];
                }
            }
        }

        return $steps;
    }

    /**
     * The capturing paths of a piece lifted from `$origin` and standing on
     * `$from`, the pieces it took still on the board; whether there was one.
     *
     * @param  list<array<int, int>>  $next
     * @param  list<int>  $taken
     * @param  list<array{0: int, 1: int, 2: list<int>, 3: bool}>  $found
     */
    private static function captures(array $next, string $board, string $foeMan, string $foeKing, bool $white, int $origin, int $from, bool $king, array $taken, array &$found): bool
    {
        $any = false;

        foreach ($next as $along) {
            $victim = $along[$from];

            while ($king && $victim >= 0 && $board[$victim] === '.') {
                $victim = $along[$victim];
            }

            if ($victim < 0 || ($board[$victim] !== $foeMan && $board[$victim] !== $foeKing) || in_array($victim, $taken, true)) {
                continue;
            }

            $landing = $along[$victim];

            if ($landing < 0 || $board[$landing] !== '.') {
                continue;
            }

            $any = true;
            $over = [...$taken, $victim];
            $ends = [];
            $continued = false;

            do {
                $kingThere = $king || ($white ? $landing >= 56 : $landing < 8);

                if (self::captures($next, $board, $foeMan, $foeKing, $white, $origin, $landing, $kingThere, $over, $found)) {
                    $continued = true;
                } else {
                    $ends[] = [$origin, $landing, $over, $kingThere];
                }

                $landing = $king ? $along[$landing] : -1;
            } while ($landing >= 0 && $board[$landing] === '.');

            // After a capture the piece goes on capturing where it can.
            if (! $continued) {
                array_push($found, ...$ends);
            }
        }

        return $any;
    }

    /** What a board is worth to white. */
    private static function weigh(string $board): int
    {
        $worth = self::$worth ?? self::worths();
        $value = 0;
        $white = 0;
        $black = 0;

        foreach (self::DARK as $place) {
            $piece = $board[$place];

            if ($piece === '.') {
                continue;
            }

            if ($piece === 'w' || $piece === 'W') {
                $value += $worth[$piece][$place];
                $white++;
            } else {
                $value -= $worth[$piece][$place];
                $black++;
            }
        }

        // The side ahead gains from every exchange.
        return $value + intdiv(($white - $black) * (24 - $white - $black), 2);
    }

    /**
     * @return list<array<int, int>>
     */
    private static function diagonals(): array
    {
        $next = [];

        foreach (self::DIRECTIONS as [$file, $rank]) {
            $along = [];

            for ($place = 0; $place < 64; $place++) {
                $along[$place] = DraughtsPosition::place(DraughtsPosition::file($place) + $file, DraughtsPosition::rank($place) + $rank) ?? -1;
            }

            $next[] = $along;
        }

        return $next;
    }

    /**
     * What each piece is worth on each square: a man 100, more the further
     * it has gone, on the four centre files and on its own first row; a
     * king 300, a little more on the main road.
     *
     * @return array{w: array<int, int>, b: array<int, int>, W: array<int, int>, B: array<int, int>}
     */
    private static function worths(): array
    {
        $worth = ['w' => [], 'b' => [], 'W' => [], 'B' => []];

        foreach (self::DARK as $place) {
            $file = DraughtsPosition::file($place);
            $rank = DraughtsPosition::rank($place);
            $centre = $file >= 2 && $file <= 5 ? 3 : ($file === 0 || $file === 7 ? -2 : 0);
            $king = 300 + ($file === $rank ? 6 : 0);

            $worth['w'][$place] = 100 + $rank * 3 + $centre + ($rank === 0 ? 7 : 0);
            $worth['b'][$place] = 100 + (7 - $rank) * 3 + $centre + ($rank === 7 ? 7 : 0);
            $worth['W'][$place] = $king;
            $worth['B'][$place] = $king;
        }

        return $worth;
    }
}
