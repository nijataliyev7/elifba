<?php

namespace App\Domain\Rooms\Support\Draughts;

use App\Domain\Rooms\Enums\RoomMatchColor;

/**
 * The one judge of a move of Russian draughts (docs/architecture.md §12
 * "Board games", ADR 034 decision 4), reshaped from the contributor's
 * `Checkers` (pull request #9), whose capture search stands as written.
 * PHP only, never mirrored in TypeScript: a page is sent the legal paths
 * and picks one. The articles are those of «Правила вида спорта "шашки"»
 * (2022), part 6.1; `tests/Fixtures/rooms/draughts/` holds what an
 * independent library answers (ADR 034 decision 32).
 *
 * - A man steps one square diagonally forward (6.1.4.3); a king along a
 *   diagonal over any number of free squares, never over a piece of its
 *   own (6.1.4.5).
 * - Capturing is compulsory, forward and backward, a man's too (6.1.5.1,
 *   6.1.5.3); a king takes a piece at any distance and lands on any free
 *   square behind it (6.1.5.2). Among several captures the choice is free,
 *   whatever their number or kind (6.1.5.14).
 * - A capture goes on to its end (6.1.5.5–6): a move is its whole path. A
 *   king that can go on capturing from some landing squares only must take
 *   one of them (6.1.5.6 with 6.1.5.2; IDF 4.6).
 * - The taken pieces leave the board when the move is over: a square may
 *   be crossed again, a piece is never jumped twice, and a taken piece
 *   blocks the way — the «Turkish strike» (6.1.5.9, 6.1.5.12, 6.1.5.17).
 * - A man that reaches the last row becomes a king (6.1.4.4); doing so
 *   while capturing, it goes on in the same move as a king (6.1.5.15),
 *   and without a capture it captures only next move (6.1.5.16).
 */
final class DraughtsRules
{
    /** The four diagonal directions, as [file, rank] steps. */
    private const array DIRECTIONS = [[-1, -1], [-1, 1], [1, -1], [1, 1]];

    /**
     * Every legal move of the side to move: its captures when it has any,
     * else its quiet moves. None when it has no piece or every piece is
     * blocked — it has lost (6.1.7).
     *
     * @return list<DraughtsMove>
     */
    public static function moves(DraughtsPosition $position): array
    {
        $side = $position->turn;
        $board = $position->board;
        $captures = [];
        $steps = [];

        for ($place = 0; $place < 64; $place++) {
            if (DraughtsPosition::colorOf($board[$place]) !== $side) {
                continue;
            }

            $king = DraughtsPosition::isKing($board[$place]);
            $lifted = $board;
            $lifted[$place] = DraughtsPosition::EMPTY;

            foreach (self::captures($lifted, $side, $place, $king, [], [$place]) as [$path, $taken, $kingAtEnd]) {
                $captures[] = self::move($path, $taken, ! $king && $kingAtEnd);
            }

            if ($captures === []) {
                foreach (self::steps($board, $side, $place, $king) as [$path, $kingAtEnd]) {
                    $steps[] = self::move($path, [], ! $king && $kingAtEnd);
                }
            }
        }

        return $captures !== [] ? $captures : $steps;
    }

    /**
     * The legal move a path of square names names, or null when it names
     * none.
     *
     * @param  list<string>  $path
     */
    public static function find(DraughtsPosition $position, array $path): ?DraughtsMove
    {
        return self::among(self::moves($position), $path);
    }

    /**
     * The move a path names among the legal moves of a position already
     * found, or null.
     *
     * @param  list<DraughtsMove>  $moves
     * @param  list<string>  $path
     */
    public static function among(array $moves, array $path): ?DraughtsMove
    {
        foreach ($moves as $move) {
            if ($move->path === $path) {
                return $move;
            }
        }

        return null;
    }

    /**
     * The position after a legal move of it (`moves()`, `find()`): the
     * piece on its last square, a king when it was one or crowned on the
     * way, the taken pieces gone, the other side to move.
     */
    public static function apply(DraughtsPosition $position, DraughtsMove $move): DraughtsPosition
    {
        $from = DraughtsNotation::place($move->from());

        return $position->after(
            $from,
            DraughtsNotation::place($move->to()),
            array_map(DraughtsNotation::place(...), $move->captured),
            $move->crowned || DraughtsPosition::isKing($position->board[$from]),
        );
    }

    /**
     * @param  list<int>  $path
     * @param  list<int>  $taken
     */
    private static function move(array $path, array $taken, bool $crowned): DraughtsMove
    {
        return new DraughtsMove(
            array_map(DraughtsNotation::square(...), $path),
            array_map(DraughtsNotation::square(...), $taken),
            $crowned,
        );
    }

    /**
     * The quiet moves of one piece, each with whether it is a king at its
     * end.
     *
     * @return list<array{0: list<int>, 1: bool}>
     */
    private static function steps(string $board, RoomMatchColor $side, int $from, bool $king): array
    {
        $moves = [];
        $forward = $side === RoomMatchColor::White ? 1 : -1;

        foreach (self::DIRECTIONS as [$df, $dr]) {
            if (! $king && $dr !== $forward) {
                continue;
            }

            for ($to = self::next($from, $df, $dr); $to !== null; $to = self::next($to, $df, $dr)) {
                if ($board[$to] !== DraughtsPosition::EMPTY) {
                    break;
                }

                $moves[] = [[$from, $to], $king || self::isLastRow($side, $to)];

                if (! $king) {
                    break;
                }
            }
        }

        return $moves;
    }

    /**
     * Every capturing path from a place, the piece lifted from its start and
     * the pieces it took still standing: each with the places it took, in
     * order, and whether it is a king at its end.
     *
     * @param  list<int>  $taken  the pieces taken so far on this path
     * @param  list<int>  $path
     * @return list<array{0: list<int>, 1: list<int>, 2: bool}>
     */
    private static function captures(string $board, RoomMatchColor $side, int $from, bool $king, array $taken, array $path): array
    {
        $found = [];

        foreach (self::DIRECTIONS as [$df, $dr]) {
            $victim = self::next($from, $df, $dr);

            // A king flies over free squares up to the piece it takes.
            while ($king && $victim !== null && $board[$victim] === DraughtsPosition::EMPTY) {
                $victim = self::next($victim, $df, $dr);
            }

            if ($victim === null
                || DraughtsPosition::colorOf($board[$victim]) !== $side->other()
                || in_array($victim, $taken, true)) {
                continue;
            }

            $landings = [];

            for ($landing = self::next($victim, $df, $dr); $landing !== null; $landing = self::next($landing, $df, $dr)) {
                if ($board[$landing] !== DraughtsPosition::EMPTY) {
                    break;
                }

                $landings[] = $landing;

                if (! $king) {
                    break;
                }
            }

            $continued = [];
            $ends = [];

            foreach ($landings as $landing) {
                $kingThere = $king || self::isLastRow($side, $landing);
                $next = self::captures($board, $side, $landing, $kingThere, [...$taken, $victim], [...$path, $landing]);

                if ($next !== []) {
                    array_push($continued, ...$next);
                } else {
                    $ends[] = [[...$path, $landing], [...$taken, $victim], $kingThere];
                }
            }

            // After a capture the piece goes on capturing where it can.
            array_push($found, ...($continued !== [] ? $continued : $ends));
        }

        return $found;
    }

    /** The next place along a diagonal, or null off the board. */
    private static function next(int $place, int $df, int $dr): ?int
    {
        return DraughtsPosition::place(DraughtsPosition::file($place) + $df, DraughtsPosition::rank($place) + $dr);
    }

    private static function isLastRow(RoomMatchColor $side, int $place): bool
    {
        return DraughtsPosition::rank($place) === ($side === RoomMatchColor::White ? 7 : 0);
    }
}
