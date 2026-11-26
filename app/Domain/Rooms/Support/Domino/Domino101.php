<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomTable;
use LogicException;

/**
 * «101» — the app's standard table (docs/architecture.md §12 "Domino",
 * ADR 038 decision 33): what makes the core's hands this game — who opens
 * (R6–R9), drawing two and keeping two (R12), the boneyard shut for a
 * double (R13), the void hand (R5), the count (R16–R19), the account with
 * its 13 (R20–R24, `DominoLedger`) and 101 (R25). Each reading of decision
 * 34 is marked where it is taken, so a correction is a case and a line.
 */
final class Domino101 implements DominoVariant
{
    /** R25. */
    public const int TARGET = 101;

    /** R21: the gain that opens an account. */
    public const int OPENS_AT = 13;

    /** R22: the small wins after which the waiting points burn. */
    public const int TRIES = 3;

    /** R12: «cüt-cüt». */
    public const int DRAW = 2;

    /** R12: the last two tiles are never drawn. */
    public const int RESERVE = 2;

    /** R16: a hand that holds `0-0` alone. */
    public const int LONE_BLANK = 10;

    public function target(): int
    {
        return self::TARGET;
    }

    public function drawSize(): int
    {
        return self::DRAW;
    }

    public function reserve(): int
    {
        return self::RESERVE;
    }

    /**
     * R6–R8. While no account is open — and in the first hand — the lowest
     * double of `1-1` … `6-6` opens with itself (R6), else the lowest tile
     * (R7; ADR 038 decision 34, reading R7: the standard list, no opening
     * draw). Once an account is open, the seat with the right (R8).
     */
    public function opener(DominoDeal $deal, ?array $right): array
    {
        if ($right !== null && $right[0] !== null) {
            return [$right[0], $right[1]];
        }

        foreach (range(1, 6) as $number) {
            $double = DominoTile::pair($number, $number);

            foreach ($deal->hands as $seat => $tiles) {
                if (in_array($double, $tiles, true)) {
                    return [$seat, RoomOpenerWhy::LowestDouble];
                }
            }
        }

        $lowest = null;

        foreach ($deal->hands as $seat => $tiles) {
            foreach ($tiles as $tile) {
                if ($lowest === null || self::lower($tile, $lowest[1])) {
                    $lowest = [$seat, $tile];
                }
            }
        }

        return [$lowest[0] ?? throw new LogicException('A deal gives every seat tiles.'), RoomOpenerWhy::LowestTile];
    }

    /**
     * R8 after a hand, while an account is open; R6 again — the deal
     * decides — while none is, and after a void hand that was opened so
     * (R5: who opens is decided again as it was for the void hand). The
     * sheet then names no seat and the lowest double at every table (ADR
     * 038 decision 66: R7 would tell that no seat holds a double of a deal
     * not yet played).
     */
    public function next(DominoHand $before, bool $anyOpen): array
    {
        if (! $anyOpen) {
            return [null, RoomOpenerWhy::LowestDouble];
        }

        return match ($before->ending) {
            RoomHandEnding::Finished => [$before->by, RoomOpenerWhy::Finished],
            RoomHandEnding::Blocked => [$this->fewest($before), RoomOpenerWhy::Fewest],
            RoomHandEnding::Tied => [$before->by, RoomOpenerWhy::Blocker],
            // R8 (d): "the same seat again" — the seat that had the right,
            // before any «Sən aç», which may give it again (ADR 038 decision 64).
            RoomHandEnding::Void => [$before->entitled, RoomOpenerWhy::Again],
            null => throw new LogicException('A hand that has not ended opens nothing.'),
        };
    }

    /** R6 opens with the double, R7 with the lowest tile, R8 and R9 with any tile. */
    public function openings(DominoHand $hand): array
    {
        $tiles = $hand->turn === null ? [] : $hand->tiles[$hand->turn];

        if ($hand->why->byRight()) {
            return $tiles;
        }

        $lowest = null;

        foreach ($tiles as $tile) {
            $better = $hand->why === RoomOpenerWhy::LowestDouble
                ? $tile->isDouble() && $tile->high > 0 && ($lowest === null || $tile->high < $lowest->high)
                : $lowest === null || self::lower($tile, $lowest);

            if ($better) {
                $lowest = $tile;
            }
        }

        return $lowest === null ? [] : [$lowest];
    }

    /**
     * R9: at `pairs`, the seat with the right of R8 may give the opening to
     * its partner before it plays; the partner cannot give it back, and
     * under R6 and R7 nobody gives.
     */
    public function mayGive(DominoHand $hand): bool
    {
        return $hand->table === RoomTable::Pairs
            && $hand->line === []
            && $hand->turn === $hand->entitled
            && $hand->why->byRight()
            && $hand->why !== RoomOpenerWhy::Given;
    }

    /**
     * R13: while no tile off the table but a double fits an end — for each
     * open number, the six tiles that pair it with another number all lie
     * in the line — nobody draws. ADR 038 decision 34, reading R13: a fact
     * of the line alone, the same for every seat; a seat that holds the
     * double still plays it (R11). ADR 038 decision 69: R13 is the
     * boneyard's — at `two` and `three` the line's fact whatever the
     * boneyard holds, at a table without a boneyard (R2) never; there a
     * seat passes because nothing is left to draw (R14).
     */
    public function closed(DominoHand $hand): bool
    {
        if ($hand->line === [] || $hand->table->boneyard() === 0) {
            return false;
        }

        $lying = [];

        foreach ($hand->line as $placed) {
            $lying[$placed['tile']->name()] = true;
        }

        foreach (array_unique($hand->ends()) as $number) {
            foreach (range(0, 6) as $other) {
                if ($other !== $number && ! isset($lying[DominoTile::pair($number, $other)->name()])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * R5: at `pairs`, when neither opponent of the seat that played the
     * hand's first tile holds a tile that fits it, the hand is void.
     * ADR 038 decision 34, reading R5: the first tile's play stands, the
     * hand is void, and the opener is found again.
     */
    public function void(DominoHand $hand): bool
    {
        if ($hand->table !== RoomTable::Pairs || count($hand->line) !== 1 || $hand->first === null) {
            return false;
        }

        $first = $hand->line[$hand->first]['tile'];
        $side = $hand->table->sideOf($hand->opener);

        foreach ($hand->tiles as $seat => $tiles) {
            if ($hand->table->sideOf($seat) === $side) {
                continue;
            }

            foreach ($tiles as $tile) {
                if ($tile->has($first->high) || $tile->has($first->low)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** R16: the sum of the tiles' pips — but `0-0` alone counts 10. */
    public function pips(array $tiles): int
    {
        if (count($tiles) === 1 && $tiles[0]->pips() === 0) {
            return self::LONE_BLANK;
        }

        return array_sum(array_map(static fn (DominoTile $tile): int => $tile->pips(), $tiles));
    }

    /**
     * R17: the finisher's side gains the pips of every seat not of its
     * side, exactly. R18: in a blocked hand the one side with the lowest
     * count gains the counts of all the others; the boneyard counts for
     * nobody. R19: a tie for the lowest — ADR 038 decision 34, reading R19:
     * any tie, at three and four sides too — gives nobody anything and
     * sends every seat's pips to the bank. A void hand counts nothing (R5):
     * its tiles and counts stand as facts, with no winner and no gain (ADR
     * 038 decision 65).
     */
    public function count(DominoHand $hand): array
    {
        $table = $hand->table;
        $left = array_map($this->pips(...), $hand->tiles);
        $counts = array_map(
            static fn (int $side): int => array_sum(array_map(static fn (int $seat): int => $left[$seat], $table->seatsOf($side))),
            range(0, $table->sides() - 1),
        );

        $nobody = ['winner' => null, 'tied' => false, 'left' => $left, 'counts' => $counts, 'gain' => 0, 'bank' => 0];

        switch ($hand->ending) {
            case RoomHandEnding::Finished:
                $winner = $table->sideOf($hand->by ?? throw new LogicException('A finished hand has a finisher.'));

                return [...$nobody, 'winner' => $winner, 'gain' => array_sum($counts) - $counts[$winner]];
            case RoomHandEnding::Blocked:
            case RoomHandEnding::Tied:
                $lowest = self::least($counts);
                $lows = array_keys($counts, $lowest, true);

                if (count($lows) > 1) {
                    return [...$nobody, 'tied' => true, 'bank' => array_sum($left)];
                }

                return [...$nobody, 'winner' => $lows[0], 'gain' => array_sum($counts) - $lowest];
            default:
                return $nobody;
        }
    }

    public function ledger(int $sides): DominoLedger
    {
        return DominoLedger::start($sides, self::OPENS_AT, self::TRIES, self::TARGET);
    }

    /**
     * The least of some counts.
     *
     * @param  list<int>  $counts
     */
    private static function least(array $counts): int
    {
        return $counts === [] ? throw new LogicException('A table has seats.') : min($counts);
    }

    /**
     * R7's order: the fewest pips, then the smaller larger number. ADR 038
     * decision 34, reading R7: `2-1` before `3-0`; `0-0`, when held, is the
     * lowest.
     */
    public static function lower(DominoTile $a, DominoTile $b): bool
    {
        return [$a->pips(), $a->high] < [$b->pips(), $b->high];
    }

    /**
     * R8 (b): after a blocked hand a side won, the seat with the fewest pips
     * left by R16, whichever its side. ADR 038 decision 34, reading R8: of
     * several, one of the winning side first; then the first in the order
     * of the turns from the seat that made the last play — that seat itself
     * first (ADR 038 decision 63).
     */
    private function fewest(DominoHand $before): int
    {
        $table = $before->table;
        $left = array_map($this->pips(...), $before->tiles);
        $seats = array_keys($left, self::least($left), true);
        $winner = $this->count($before)['winner'];
        $winning = array_values(array_filter($seats, static fn (int $seat): bool => $table->sideOf($seat) === $winner));

        if ($winning !== []) {
            $seats = $winning;
        }

        $by = $before->by ?? 0;

        foreach (range(0, $table->seats() - 1) as $step) {
            $seat = ($by + $step) % $table->seats();

            if (in_array($seat, $seats, true)) {
                return $seat;
            }
        }

        throw new LogicException('A blocked hand has a seat with the fewest pips.');
    }
}
