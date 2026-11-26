<?php

namespace App\Domain\Rooms\Support\Domino;

use InvalidArgumentException;

/**
 * The one judge of a domino hand (docs/architecture.md §12 "Domino", ADR
 * 038 decisions 15, 18, 33): the plays a seat may make, where a tile lies,
 * and what follows a play by itself — the next seats drawing and passing
 * round the table to one that can play, or to the end of a blocked hand.
 * What makes the hand «101» — who opens with what, a draw's size and the
 * tiles kept, the boneyard shut for a double, the count — it asks the
 * game's `DominoVariant`; `DominoHand` folds a hand with it. Pure, PHP
 * only, never mirrored in TypeScript.
 *
 * @phpstan-type Placed array{tile: DominoTile, left: int, right: int}
 * @phpstan-type Play array{tile: DominoTile, ends: list<string>}
 * @phpstan-type Then array{seat: int, drew: int, passed: bool}
 */
final class DominoRules
{
    /**
     * The number open at each end of a line, by the end's name (R10): the
     * left end's first; none before the first tile.
     *
     * @param  list<Placed>  $line
     * @return array<string, int>
     */
    public static function ends(array $line): array
    {
        if ($line === []) {
            return [];
        }

        return [
            DominoTile::LEFT => $line[0]['left'],
            DominoTile::RIGHT => $line[count($line) - 1]['right'],
        ];
    }

    /**
     * The ends a tile may go to (R10): each whose open number it has. Where
     * two ends show one number the first is listed alone — the plays give
     * one position (ADR 038 decision 38: the left).
     *
     * @param  array<string, int>  $ends
     * @return list<string>
     */
    public static function endsFor(DominoTile $tile, array $ends): array
    {
        $fits = [];
        $seen = [];

        foreach ($ends as $end => $number) {
            if (! in_array($number, $seen, true) && $tile->has($number)) {
                $fits[] = $end;
            }

            $seen[] = $number;
        }

        return $fits;
    }

    /**
     * The plays a seat holding `$tiles` may make, in the order it holds
     * them (R10, R11): before the first tile, the opening tiles the variant
     * allows, with no end; afterwards every tile that fits an end, with
     * the ends it fits.
     *
     * @param  list<DominoTile>  $tiles
     * @param  array<string, int>  $ends
     * @param  list<DominoTile>  $openings
     * @return list<Play>
     */
    public static function plays(array $tiles, array $ends, array $openings): array
    {
        if ($ends === []) {
            return array_map(static fn (DominoTile $tile): array => ['tile' => $tile, 'ends' => []], $openings);
        }

        $plays = [];

        foreach ($tiles as $tile) {
            $fits = self::endsFor($tile, $ends);

            if ($fits !== []) {
                $plays[] = ['tile' => $tile, 'ends' => $fits];
            }
        }

        return $plays;
    }

    /**
     * Whether any of the tiles fits an end.
     *
     * @param  list<DominoTile>  $tiles
     * @param  array<string, int>  $ends
     */
    public static function fits(array $tiles, array $ends): bool
    {
        foreach ($tiles as $tile) {
            if (self::endsFor($tile, $ends) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The line with a tile put at an end (R10): the end's open number is
     * then the tile's other number. A hand's first tile lies as its name
     * reads, the larger number on the left (ADR 038 silent: decision 38's
     * `line` gives each tile "as it lies" and names no side for the first).
     *
     * @param  list<Placed>  $line
     * @return list<Placed>
     */
    public static function place(array $line, DominoTile $tile, ?string $end): array
    {
        if ($line === []) {
            return [['tile' => $tile, 'left' => $tile->high, 'right' => $tile->low]];
        }

        $number = self::ends($line)[$end ?? ''] ?? throw new InvalidArgumentException('A tile goes to the left or the right end.');

        if (! $tile->has($number)) {
            throw new InvalidArgumentException("{$tile->name()} does not fit {$number}.");
        }

        return $end === DominoTile::LEFT
            ? [['tile' => $tile, 'left' => $tile->other($number), 'right' => $number], ...$line]
            : [...$line, ['tile' => $tile, 'left' => $number, 'right' => $tile->other($number)]];
    }

    /**
     * What follows a play by itself (ADR 038 decision 18): from the seat
     * after `$mover`, each seat that holds no fitting tile draws while it
     * may (R12) and the boneyard is not shut (R13), then plays (R11) — the
     * turn is its — or passes (R14), and so round the table. When every
     * seat has passed, the mover too, the hand is blocked (R15): the turn
     * is null. Drawing takes the variant's draw size, but never below its
     * reserve — ADR 038 decision 34, reading R12: with three left a seat
     * draws one.
     *
     * @param  list<list<DominoTile>>  $tiles  every seat's tiles after the play
     * @param  list<DominoTile>  $boneyard  in the order it is drawn
     * @param  array<string, int>  $ends  the line's ends after the play
     * @param  bool  $closed  the boneyard is shut by the line (R13)
     * @return array{tiles: list<list<DominoTile>>, boneyard: list<DominoTile>, turn: int|null, then: list<Then>, drawn: array<int, list<DominoTile>>}
     */
    public static function follow(array $tiles, array $boneyard, array $ends, bool $closed, int $mover, int $drawSize, int $reserve): array
    {
        $seats = count($tiles);
        $then = [];
        $drawn = [];
        $passes = 0;
        $seat = ($mover + 1) % $seats;

        while (true) {
            $took = [];

            while (! self::fits($tiles[$seat], $ends) && ! $closed && count($boneyard) > $reserve) {
                $step = array_splice($boneyard, 0, min($drawSize, count($boneyard) - $reserve));
                $took = [...$took, ...$step];
                $tiles[$seat] = [...$tiles[$seat], ...$step];
            }

            if ($took !== []) {
                $drawn[$seat] = $took;
            }

            if (self::fits($tiles[$seat], $ends)) {
                if ($took !== []) {
                    $then[] = ['seat' => $seat, 'drew' => count($took), 'passed' => false];
                }

                return ['tiles' => $tiles, 'boneyard' => $boneyard, 'turn' => $seat, 'then' => $then, 'drawn' => $drawn];
            }

            $then[] = ['seat' => $seat, 'drew' => count($took), 'passed' => true];

            if (++$passes === $seats) {
                return ['tiles' => $tiles, 'boneyard' => $boneyard, 'turn' => null, 'then' => $then, 'drawn' => $drawn];
            }

            $seat = ($seat + 1) % $seats;
        }
    }
}
