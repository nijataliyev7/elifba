<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rankings\Support\DenseRank;
use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomLedgerLine;
use InvalidArgumentException;

/**
 * The account of a game of «101» between its hands (docs/architecture.md
 * §12 "Domino", ADR 038 R20–R26), a fold of the hands' results: each
 * side's balance, whether its account is open, its waiting points and its
 * tries, and the bank. Pure and immutable — each hand gives a new ledger
 * and the lines that changed it (`lines`), in the order they happened.
 *
 * The order of the lines of one hand (ADR 038 silent: decision 38 asks for
 * them "in order" and names none): the winner's own — `gained`, or
 * `opened` then `released`, or `waited` then `burnt_third` —, then the
 * bank — `bank_taken` or `bank_burnt` —, then each burnt rival in the
 * order of the sides. A change of nothing is no line.
 *
 * @phpstan-type Side array{open: bool, balance: int, waiting: int, tries: int}
 * @phpstan-type Line array{side: int|null, line: RoomLedgerLine, points: int}
 */
final readonly class DominoLedger
{
    /**
     * @param  list<Side>  $sides  in the order of the sides
     * @param  list<Line>  $lines  what the last hand changed, in order
     */
    private function __construct(
        public array $sides,
        public int $bank,
        public array $lines,
        private int $opensAt,
        private int $tries,
        private int $target,
    ) {}

    /**
     * Every side's account closed, at 0, nothing waiting and no tries; the
     * bank empty (R20).
     *
     * @param  int  $opensAt  the gain that opens an account (R21: 13)
     * @param  int  $tries  the small wins after which the waiting points burn (R22: 3)
     * @param  int  $target  the balance that ends the game (R25: 101)
     */
    public static function start(int $sides, int $opensAt, int $tries, int $target): self
    {
        return new self(
            array_fill(0, $sides, ['open' => false, 'balance' => 0, 'waiting' => 0, 'tries' => 0]),
            0,
            [],
            $opensAt,
            $tries,
            $target,
        );
    }

    /**
     * The account after a hand: a win of a side (`finished`, `blocked`) by
     * its gain, a tie by the pips that go to the bank, a void hand by
     * nothing.
     */
    public function after(RoomHandEnding $ending, ?int $winner, int $points): self
    {
        return match ($ending) {
            RoomHandEnding::Finished, RoomHandEnding::Blocked => $this->win($winner ?? throw new InvalidArgumentException('A won hand has a winner.'), $points),
            RoomHandEnding::Tied => $this->tie($points),
            RoomHandEnding::Void => new self($this->sides, $this->bank, [], $this->opensAt, $this->tries, $this->target),
        };
    }

    /** A side won a hand and gained `$gain` (R20–R24). */
    public function win(int $side, int $gain): self
    {
        if (! isset($this->sides[$side])) {
            throw new InvalidArgumentException("No side {$side}.");
        }

        $sides = $this->sides;
        $bank = $this->bank;
        $lines = [];
        $account = $sides[$side];

        if ($account['open']) {
            // R20: an open account adds its gain to its balance.
            $account['balance'] += $gain;
            $lines[] = ['side' => $side, 'line' => RoomLedgerLine::Gained, 'points' => $gain];
        } elseif ($gain >= $this->opensAt) {
            // R21: 13 or more opens the account and takes the waiting points with it.
            $account['open'] = true;
            $account['balance'] += $gain;
            $lines[] = ['side' => $side, 'line' => RoomLedgerLine::Opened, 'points' => $gain];

            if ($account['waiting'] > 0) {
                $account['balance'] += $account['waiting'];
                $lines[] = ['side' => $side, 'line' => RoomLedgerLine::Released, 'points' => $account['waiting']];
            }

            $account['waiting'] = 0;
            $account['tries'] = 0;
        } else {
            // R22: a smaller win waits; at the third try the waiting points
            // burn, the third gain with them. ADR 038 decision 34, reading
            // R22: the count of tries starts again after the burn.
            $account['waiting'] += $gain;
            $account['tries']++;
            $lines[] = ['side' => $side, 'line' => RoomLedgerLine::Waited, 'points' => $gain];

            if ($account['tries'] >= $this->tries) {
                $lines[] = ['side' => $side, 'line' => RoomLedgerLine::BurntThird, 'points' => $account['waiting']];
                $account['waiting'] = 0;
                $account['tries'] = 0;
            }
        }

        // R24: the bank goes to a winner whose account is or becomes open,
        // and burns with one whose account stays closed. It never counts
        // toward the 13: the opening above saw the hand's gain alone.
        if ($bank > 0) {
            if ($account['open']) {
                $account['balance'] += $bank;
                $lines[] = ['side' => $side, 'line' => RoomLedgerLine::BankTaken, 'points' => $bank];
            } else {
                $lines[] = ['side' => null, 'line' => RoomLedgerLine::BankBurnt, 'points' => $bank];
            }

            $bank = 0;
        }

        $sides[$side] = $account;

        // R23: every win of an open account — the hand that opens it
        // included — burns the waiting points of every closed account; a
        // win of an account that stays closed burns nobody's. R22: it
        // changes no count of tries.
        if ($account['open']) {
            foreach ($sides as $other => $rival) {
                if ($other !== $side && ! $rival['open'] && $rival['waiting'] > 0) {
                    $lines[] = ['side' => $other, 'line' => RoomLedgerLine::BurntRival, 'points' => $rival['waiting']];
                    $sides[$other]['waiting'] = 0;
                }
            }
        }

        return new self($sides, $bank, $lines, $this->opensAt, $this->tries, $this->target);
    }

    /** A tied hand: its pips go to the bank and nobody's account changes (R19, R24). */
    public function tie(int $pips): self
    {
        return new self(
            $this->sides,
            $this->bank + $pips,
            $pips > 0 ? [['side' => null, 'line' => RoomLedgerLine::BankAdded, 'points' => $pips]] : [],
            $this->opensAt,
            $this->tries,
            $this->target,
        );
    }

    /** Whether any side's account is open (R6, R8). */
    public function anyOpen(): bool
    {
        foreach ($this->sides as $side) {
            if ($side['open']) {
                return true;
            }
        }

        return false;
    }

    /** The side whose balance reached the target: the game is over and it has won (R25); null while none has. */
    public function winner(): ?int
    {
        foreach ($this->sides as $index => $side) {
            if ($side['balance'] >= $this->target) {
                return $index;
            }
        }

        return null;
    }

    /**
     * A dry win (R26): the game is won and every other side's balance is 0.
     * ADR 038 decision 34, reading R26: at three and four sides, every
     * other side.
     */
    public function dry(): bool
    {
        $winner = $this->winner();

        if ($winner === null) {
            return false;
        }

        foreach ($this->sides as $index => $side) {
            if ($index !== $winner && $side['balance'] !== 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * The sides in the server's order — by balance, the highest first,
     * then by side (ADR 038 decision 38) — each with its dense place.
     *
     * @return list<array{side: int, place: int}>
     */
    public function standing(): array
    {
        $balances = array_map(static fn (array $side): int => $side['balance'], $this->sides);
        $places = DenseRank::places($balances);
        $order = array_keys($balances);

        usort($order, static fn (int $a, int $b): int => [$balances[$b], $a] <=> [$balances[$a], $b]);

        return array_map(static fn (int $side): array => ['side' => $side, 'place' => $places[$side]], $order);
    }

    /** What the side still needs to reach the target. */
    public function toTarget(int $side): int
    {
        return max(0, $this->target - $this->sides[$side]['balance']);
    }
}
