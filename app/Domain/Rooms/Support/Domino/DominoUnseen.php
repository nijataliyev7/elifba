<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomOpenerWhy;
use App\Domain\Rooms\Enums\RoomTable;
use LogicException;
use Random\Randomizer;

/**
 * The tiles one seat of a domino hand has not seen, and deals of them that
 * agree with all it saw (docs/architecture.md §12 "Domino (Y4–Y6)" → "The
 * computer", ADR 038 decisions 54–55): what «Çətin» plays its plays out
 * in. Built from a `DominoView` alone — its own tiles, the line, the
 * counts and the hand's plays, draws and passes by seat — so a guess can
 * hold nothing the seat could not know.
 *
 * What the view tells of the tiles another seat held:
 *
 * - a seat that passed held no tile with a number open at either end
 *   (R14); a seat that drew held none before it drew (R12), nor after each
 *   of its draws but the last — and after the last it held one, the tile
 *   it played next, or none when it passed;
 * - a tile a seat played was in its hand from when it was dealt or drawn
 *   until it played it, and so had none of the numbers it was void of in
 *   between;
 * - R6: no seat was dealt a double lower than the one the hand opened
 *   with; R7: no seat was dealt a double of `1-1` … `6-6`, nor a tile
 *   lower than the one it opened with;
 * - R5: at `pairs`, an opponent of the seat that played the first tile
 *   held a tile that fits it, or the hand would be void.
 *
 * R4 — a deal some seat holds five doubles or five of a number in is dealt
 * again — is not asked of a guess: it tells almost nothing.
 *
 * A seat's hand is a row of acquisitions — the seven dealt, then each
 * pair (or single) it drew — and a tile held since an acquisition avoids
 * every number the seat was void of since. The tiles a seat is known to
 * have held (what it played; for the seat itself also what it holds) go to
 * the earliest acquisitions they can, so the places left for unseen tiles
 * are the least bound; then each deal fills those places from the unseen
 * tiles shuffled, as a bipartite matching, so a deal that agrees is always
 * found when one exists. The rest is the boneyard, in the shuffled order.
 *
 * Every deal also gives the hand as it was dealt — each seat's seven and
 * the boneyard in the order drawn —, from which the judge playing the same
 * plays shows the seat exactly what it saw (the engine's test holds it).
 *
 * @phpstan-type Place array{seat: int, at: int, avoid: int, dealt: bool}
 * @phpstan-type Deal array{hands: list<list<DominoTile>>, boneyard: list<DominoTile>, dealt: list<list<DominoTile>>, stock: list<DominoTile>}
 */
final class DominoUnseen
{
    /** The times of a hand's events, far enough apart for the draws between them. */
    private const int STEP = 100;

    /** A known tile still held is held at this time. */
    private const int NOW = PHP_INT_MAX;

    /** Deals tried for one that agrees with R5's reply before the last is taken as it is. */
    private const int TRIES = 16;

    /**
     * @param  list<DominoTile>  $tiles  the tiles the seat has not seen, in the order of `DominoTile::all()`
     * @param  list<Place>  $places  every place in another seat's hand now that an unseen tile fills, the most bound first
     * @param  array<string, true>  $barred  the tiles no seat was dealt (R6, R7)
     * @param  array<int, array<int, list<DominoTile>>>  $known  by seat, then by an acquisition's time, the tiles known to have come in it
     * @param  list<list<DominoTile>>  $held  every seat's tiles now: the seat's own, the others' empty
     * @param  array{opener: int, numbers: int}|null  $reply  R5: an opponent of the opener was dealt a tile with one of these numbers
     */
    private function __construct(
        public readonly RoomTable $table,
        public readonly array $tiles,
        private readonly array $places,
        private readonly array $barred,
        private readonly array $known,
        private readonly array $held,
        private readonly ?array $reply,
    ) {}

    /**
     * What a seat has not seen, and what it saw of where those tiles are.
     */
    public static function of(DominoView $view): self
    {
        $table = $view->table;
        $seats = $table->seats();

        /** @var list<list<array{at: int, size: int}>> $acquired */
        $acquired = array_fill(0, $seats, [['at' => 0, 'size' => RoomTable::HAND]]);
        /** @var list<list<array{at: int, numbers: int}>> $voids */
        $voids = array_fill(0, $seats, []);
        /** @var list<list<array{at: int, tile: DominoTile}>> $known */
        $known = array_fill(0, $seats, []);
        $line = [];
        $stock = $table->boneyard();
        $first = null;
        $opener = null;

        foreach ($view->events as $index => $event) {
            $at = ($index + 1) * self::STEP;
            $seat = $event['seat'];

            if ($event['kind'] === 'play' && $event['tile'] !== null) {
                $known[$seat][] = ['at' => $at, 'tile' => $event['tile']];

                if ($line === []) {
                    $first = $event['tile'];
                    $opener = $seat;
                }

                $line = DominoRules::place($line, $event['tile'], $event['end']);
            } elseif ($event['kind'] === 'pass') {
                $voids[$seat][] = ['at' => $at, 'numbers' => self::numbers(DominoRules::ends($line))];
            } elseif ($event['kind'] === 'draw') {
                // R12: void before the draw and after each pair but the last.
                $numbers = self::numbers(DominoRules::ends($line));
                $voids[$seat][] = ['at' => $at, 'numbers' => $numbers];
                $left = $event['count'];

                for ($step = 1; $left > 0; $step++) {
                    $size = min(Domino101::DRAW, $stock - Domino101::RESERVE, $left);
                    $size = $size > 0 ? $size : $left;
                    $acquired[$seat][] = ['at' => $at + 2 * $step - 1, 'size' => $size];
                    $stock -= $size;
                    $left -= $size;

                    if ($left > 0) {
                        $voids[$seat][] = ['at' => $at + 2 * $step, 'numbers' => $numbers];
                    }
                }
            }
        }

        if ($stock !== $view->boneyard) {
            throw new LogicException('A view whose draws do not add up to its boneyard.');
        }

        foreach ($view->tiles as $tile) {
            $known[$view->seat][] = ['at' => self::NOW, 'tile' => $tile];
        }

        $barred = self::barred($view, $first ?? self::opening($view));
        $places = [];
        $byTime = [];

        foreach (range(0, $seats - 1) as $seat) {
            [$free, $byTime[$seat]] = self::assign($acquired[$seat], $voids[$seat], $known[$seat], $barred);

            foreach ($free as $place) {
                if ($seat === $view->seat) {
                    throw new LogicException('A seat whose own tiles do not fill its hand.');
                }

                $places[] = ['seat' => $seat, ...$place];
            }
        }

        $seen = [];

        foreach ([...$view->tiles, ...array_column($view->line, 'tile')] as $tile) {
            $seen[$tile->name()] = true;
        }

        $tiles = array_values(array_filter(DominoTile::all(), static fn (DominoTile $tile): bool => ! isset($seen[$tile->name()])));

        if (count($tiles) !== count($places) + $view->boneyard) {
            throw new LogicException('A view whose counts do not add up to the 28.');
        }

        // The most bound place first, so the matching meets the hard ones early.
        usort($places, static fn (array $a, array $b): int => [self::bound($b, $barred), $a['seat'], $a['at']] <=> [self::bound($a, $barred), $b['seat'], $b['at']]);

        $held = array_fill(0, $seats, []);
        $held[$view->seat] = $view->tiles;
        $reply = $table === RoomTable::Pairs && $first !== null && $opener !== null
            ? ['opener' => $opener, 'numbers' => (1 << $first->high) | (1 << $first->low)]
            : null;

        return new self($table, $tiles, $places, $barred, $byTime, $held, $reply);
    }

    /**
     * A deal of the unseen tiles that agrees with what the seat saw: every
     * seat's tiles and the boneyard now, and the hand as it was dealt.
     *
     * @return Deal
     */
    public function deal(Randomizer $random): array
    {
        $deal = $this->guess($random);

        for ($try = 1; $try < self::TRIES && ! $this->answered($deal['dealt']); $try++) {
            $deal = $this->guess($random);
        }

        return $deal;
    }

    /**
     * One deal: the unseen tiles shuffled, matched to the places they may
     * fill, the rest the boneyard.
     *
     * @return Deal
     */
    private function guess(Randomizer $random): array
    {
        /** @var list<DominoTile> $order */
        $order = $random->shuffleArray($this->tiles);
        $owner = array_fill(0, count($order), -1);
        $tileOf = array_fill(0, count($this->places), -1);

        foreach (array_keys($this->places) as $place) {
            $seen = [];
            $this->augment($place, $order, $owner, $tileOf, $seen);
        }

        // A place the matching could not fill — never for a view the judge
        // gave — takes the first tile left, so a deal is always whole.
        foreach ($tileOf as $place => $index) {
            if ($index === -1) {
                $index = (int) array_search(-1, $owner, true);
                $owner[$index] = $place;
                $tileOf[$place] = $index;
            }
        }

        $hands = $this->held;
        $came = $this->known;

        foreach ($this->places as $place => ['seat' => $seat, 'at' => $at]) {
            $tile = $order[$tileOf[$place]];
            $hands[$seat][] = $tile;
            $came[$seat][$at][] = $tile;
        }

        $boneyard = [];

        foreach ($order as $index => $tile) {
            if ($owner[$index] === -1) {
                $boneyard[] = $tile;
            }
        }

        $dealt = [];
        $drawn = [];

        foreach ($came as $seat => $acquisitions) {
            $dealt[$seat] = $acquisitions[0] ?? [];

            foreach ($acquisitions as $at => $tiles) {
                if ($at > 0) {
                    $drawn[$at] = $tiles;
                }
            }
        }

        ksort($drawn);

        return [
            'hands' => $hands,
            'boneyard' => $boneyard,
            'dealt' => array_values($dealt),
            'stock' => [...array_merge(...array_values($drawn)), ...$boneyard],
        ];
    }

    /**
     * Kuhn's augmenting path from a place: a tile it may hold that is free,
     * or held by a place that can take another.
     *
     * @param  list<DominoTile>  $order
     * @param  array<int, int>  $owner  by tile, the place it fills or -1
     * @param  array<int, int>  $tileOf  by place, the tile it holds or -1
     * @param  array<int, true>  $seen
     */
    private function augment(int $place, array $order, array &$owner, array &$tileOf, array &$seen): bool
    {
        foreach ($order as $index => $tile) {
            if (isset($seen[$index]) || ! $this->fits($this->places[$place], $tile)) {
                continue;
            }

            $seen[$index] = true;

            if ($owner[$index] === -1 || $this->augment($owner[$index], $order, $owner, $tileOf, $seen)) {
                $owner[$index] = $place;
                $tileOf[$place] = $index;

                return true;
            }
        }

        return false;
    }

    /**
     * @param  Place  $place
     */
    private function fits(array $place, DominoTile $tile): bool
    {
        return (self::numbers([$tile->high, $tile->low]) & $place['avoid']) === 0
            && ! ($place['dealt'] && isset($this->barred[$tile->name()]));
    }

    /**
     * R5: at `pairs`, an opponent of the first tile's player was dealt a
     * tile that fits it.
     *
     * @param  list<list<DominoTile>>  $dealt
     */
    private function answered(array $dealt): bool
    {
        if ($this->reply === null) {
            return true;
        }

        $side = $this->table->sideOf($this->reply['opener']);

        foreach ($dealt as $seat => $tiles) {
            if ($this->table->sideOf($seat) === $side) {
                continue;
            }

            foreach ($tiles as $tile) {
                if ((self::numbers([$tile->high, $tile->low]) & $this->reply['numbers']) !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The known tiles of one seat in the earliest acquisitions each can have
     * come in, and the places left — each held since its acquisition, so
     * avoiding every number the seat was void of since. An acquisition's
     * place is filled by the known tile whose latest possible acquisition
     * is the earliest (as a deadline schedules), so a known tile is never
     * left without one when a way exists.
     *
     * @param  list<array{at: int, size: int}>  $acquired
     * @param  list<array{at: int, numbers: int}>  $voids
     * @param  list<array{at: int, tile: DominoTile}>  $known
     * @param  array<string, true>  $barred
     * @return array{0: list<array{at: int, avoid: int, dealt: bool}>, 1: array<int, list<DominoTile>>}
     */
    private static function assign(array $acquired, array $voids, array $known, array $barred): array
    {
        $avoid = static function (int $from, int $until) use ($voids): int {
            $numbers = 0;

            foreach ($voids as $void) {
                if ($void['at'] > $from && $void['at'] < $until) {
                    $numbers |= $void['numbers'];
                }
            }

            return $numbers;
        };

        $latest = [];

        foreach ($known as $index => ['at' => $at, 'tile' => $tile]) {
            $latest[$index] = -1;

            foreach ($acquired as $slot => $acquisition) {
                if (self::may($acquisition['at'], $at, $tile, $avoid, $barred)) {
                    $latest[$index] = $slot;
                }
            }
        }

        $left = array_keys($known);
        $byTime = [];
        $free = [];

        foreach ($acquired as $slot => $acquisition) {
            $fitting = array_values(array_filter($left, static fn (int $index): bool => self::may($acquisition['at'], $known[$index]['at'], $known[$index]['tile'], $avoid, $barred)));
            usort($fitting, static fn (int $a, int $b): int => [$latest[$a], $known[$a]['at'], $a] <=> [$latest[$b], $known[$b]['at'], $b]);
            $taken = array_slice($fitting, 0, $acquisition['size']);
            $byTime[$acquisition['at']] = array_map(static fn (int $index): DominoTile => $known[$index]['tile'], $taken);
            $left = array_values(array_diff($left, $taken));

            for ($place = count($taken); $place < $acquisition['size']; $place++) {
                $free[] = ['at' => $acquisition['at'], 'avoid' => $avoid($acquisition['at'], self::NOW), 'dealt' => $slot === 0];
            }
        }

        // A known tile no acquisition can hold — never for a view the judge
        // gave — goes to the latest before it, so the hand stays whole.
        foreach ($left as $index) {
            $at = 0;

            foreach ($acquired as $acquisition) {
                if ($acquisition['at'] < $known[$index]['at']) {
                    $at = $acquisition['at'];
                }
            }

            foreach ($free as $place => $open) {
                if ($open['at'] === $at) {
                    unset($free[$place]);

                    break;
                }
            }

            $byTime[$at][] = $known[$index]['tile'];
        }

        return [array_values($free), $byTime];
    }

    /**
     * Whether a tile can have come in an acquisition at `$from` and stayed
     * until `$until`: it has none of the numbers the seat was void of in
     * between, and was not barred from the deal.
     *
     * @param  callable(int, int): int  $avoid
     * @param  array<string, true>  $barred
     */
    private static function may(int $from, int $until, DominoTile $tile, callable $avoid, array $barred): bool
    {
        return $from < $until
            && (self::numbers([$tile->high, $tile->low]) & $avoid($from, $until)) === 0
            && ! ($from === 0 && isset($barred[$tile->name()]));
    }

    /**
     * The tiles no seat was dealt, by what the hand opened with: under R6
     * every lower double, under R7 every double of `1-1` … `6-6` and every
     * lower tile; under R8 nothing.
     *
     * @return array<string, true>
     */
    private static function barred(DominoView $view, ?DominoTile $first): array
    {
        $barred = [];

        if ($first === null) {
            return $barred;
        }

        foreach (DominoTile::all() as $name => $tile) {
            $lower = match ($view->why) {
                RoomOpenerWhy::LowestDouble => $tile->isDouble() && $tile->high > 0 && $tile->high < $first->high,
                RoomOpenerWhy::LowestTile => ($tile->isDouble() && $tile->high > 0) || Domino101::lower($tile, $first),
                default => false,
            };

            if ($lower) {
                $barred[$name] = true;
            }
        }

        return $barred;
    }

    /** The tile the seat must open with when the hand's first tile is its to play under R6 or R7. */
    private static function opening(DominoView $view): ?DominoTile
    {
        return $view->line === [] && count($view->legal) === 1 ? $view->legal[0]['tile'] : null;
    }

    /**
     * How bound a place is: the numbers it avoids, and one more when it was
     * dealt under R6 or R7.
     *
     * @param  array{at: int, avoid: int, dealt: bool}  $place
     * @param  array<string, true>  $barred
     */
    private static function bound(array $place, array $barred): int
    {
        return substr_count(decbin($place['avoid']), '1') + ($place['dealt'] && $barred !== [] ? 1 : 0);
    }

    /**
     * Numbers as bits.
     *
     * @param  array<array-key, int>  $numbers
     */
    private static function numbers(array $numbers): int
    {
        $bits = 0;

        foreach ($numbers as $number) {
            $bits |= 1 << $number;
        }

        return $bits;
    }
}
