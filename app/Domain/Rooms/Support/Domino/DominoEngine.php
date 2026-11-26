<?php

namespace App\Domain\Rooms\Support\Domino;

use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomHandEnding;
use App\Domain\Rooms\Enums\RoomTable;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * The computer's play in a hand of domino «101» (docs/architecture.md §12
 * "Domino (Y4–Y6)" → "The computer", ADR 038 decisions 54–56). It decides
 * from a `DominoView` — what its seat has seen — and a random source, and
 * from nothing else: it is given no game, hand or deal, and names none, so
 * that it cannot peek is a property of its types (`RoomsApartTest`). It
 * judges nothing: the play it returns is one of the view's `legal`, the
 * judge's own list, with one of the ends listed for it. It only plays —
 * never «Sən aç» (decision 56).
 *
 * The levels (`RoomComputerLevel`, the computer's own, never the game's
 * difficulty, which is a turn's time):
 *
 * - «Asan» plays one of its plays at random.
 * - «Orta» sheds the most pips; of tiles with the same pips a double
 *   first; then the play that leaves open the numbers it holds most of.
 *   Ties go to the tiles' one order, the left end first.
 * - «Çətin» deals the tiles it has not seen `deals()` times in ways that
 *   agree with every pass and draw it saw (`DominoUnseen`), plays each of
 *   its plays out to the hand's end in each — every seat then playing as
 *   «Orta» — and takes the play whose hands came out best for its side:
 *   what its side gained less what another side gained, summed. Ties go
 *   to «Orta»'s order.
 *
 * A level is a count, never a time, and every sum is an integer: the same
 * view, level and source give the same play on every machine. The seat's
 * tiles are taken in the tiles' one order, never the order it got them in,
 * so ties fall the same way in every view that differs only in that.
 *
 * @phpstan-type Option array{tile: DominoTile, end: string|null}
 */
final class DominoEngine
{
    /** «Orta»'s order: the pips first, then a double, then the numbers kept open. */
    private const int PIPS = 1024;

    private const int DOUBLE = 512;

    /**
     * The play the computer makes: a tile of the view's `legal` and one of
     * the ends listed for it — null only for a hand's first tile.
     *
     * @return Option
     */
    public static function choose(DominoView $view, RoomComputerLevel $level, Randomizer $random): array
    {
        $view = self::sorted($view);
        $options = self::options($view->legal);

        if ($options === []) {
            throw new InvalidArgumentException('The seat has no play.');
        }

        if (count($options) === 1) {
            return $options[0];
        }

        return match ($level) {
            RoomComputerLevel::Easy => $options[$random->getInt(0, count($options) - 1)],
            RoomComputerLevel::Medium => $options[self::ranked($options, $view->tiles, $view->ends)[0]],
            RoomComputerLevel::Hard => self::lookAhead($view, $options, $level->deals(), $random),
        };
    }

    /**
     * The view with the seat's tiles and its plays in the tiles' one order
     * (`DominoTile::all()`): the order it got them in tells nothing of the
     * hand, so a view that differs only in it gets the same play.
     */
    private static function sorted(DominoView $view): DominoView
    {
        $rank = array_flip(array_keys(DominoTile::all()));
        $tiles = $view->tiles;
        $legal = $view->legal;
        usort($tiles, static fn (DominoTile $a, DominoTile $b): int => $rank[$a->name()] <=> $rank[$b->name()]);
        usort($legal, static fn (array $a, array $b): int => $rank[$a['tile']->name()] <=> $rank[$b['tile']->name()]);

        return new DominoView(
            seat: $view->seat,
            table: $view->table,
            hand: $view->hand,
            opener: $view->opener,
            why: $view->why,
            turn: $view->turn,
            tiles: $tiles,
            line: $view->line,
            ends: $view->ends,
            held: $view->held,
            boneyard: $view->boneyard,
            drawable: $view->drawable,
            closed: $view->closed,
            events: $view->events,
            legal: $legal,
            sides: $view->sides,
            bank: $view->bank,
        );
    }

    /**
     * «Çətin»: each play played out in `$deals` deals of the unseen tiles.
     *
     * @param  list<Option>  $options
     * @return Option
     */
    private static function lookAhead(DominoView $view, array $options, int $deals, Randomizer $random): array
    {
        $sums = array_fill(0, count($options), 0);

        if ($deals > 0) {
            $unseen = DominoUnseen::of($view);
            $rules = new Domino101;
            $lying = [];

            foreach ($view->line as $placed) {
                $lying[$placed['tile']->name()] = true;
            }

            for ($deal = 0; $deal < $deals; $deal++) {
                ['hands' => $hands, 'boneyard' => $boneyard] = $unseen->deal($random);

                foreach ($options as $index => $option) {
                    $sums[$index] += self::playOut($rules, $view, $hands, $boneyard, $lying, $option);
                }
            }
        }

        $best = null;

        foreach (self::ranked($options, $view->tiles, $view->ends) as $index) {
            if ($best === null || $sums[$index] > $sums[$best]) {
                $best = $index;
            }
        }

        return $options[$best ?? 0];
    }

    /**
     * A hand played out from the seat's play to its end, every seat then
     * playing as «Orta»: what the seat's side gained, less what another
     * side gained; nothing for a tied or void hand.
     *
     * @param  list<list<DominoTile>>  $hands
     * @param  list<DominoTile>  $boneyard
     * @param  array<string, true>  $lying
     * @param  Option  $option
     */
    private static function playOut(Domino101 $rules, DominoView $view, array $hands, array $boneyard, array $lying, array $option): int
    {
        $table = $view->table;
        $ends = $view->ends;
        $seat = $view->seat;
        $opening = $ends === [];
        ['tile' => $tile, 'end' => $end] = $option;

        while (true) {
            $hands[$seat] = array_values(array_filter($hands[$seat], static fn (DominoTile $held): bool => $held !== $tile));
            $ends = self::after($ends, $tile, $end);
            $lying[$tile->name()] = true;

            if ($hands[$seat] === []) {
                return self::worth($rules, $table, RoomHandEnding::Finished, $seat, $hands, $view->side());
            }

            // R5: a first tile nobody of the other side can answer.
            if ($opening && Domino101::unanswered($table, $seat, $tile, $hands)) {
                return 0;
            }

            $opening = false;
            $follow = DominoRules::follow($hands, $boneyard, $ends, Domino101::shut($table, $ends, $lying), $seat, Domino101::DRAW, Domino101::RESERVE);
            $hands = $follow['tiles'];
            $boneyard = $follow['boneyard'];

            if ($follow['turn'] === null) {
                return self::worth($rules, $table, RoomHandEnding::Blocked, $seat, $hands, $view->side());
            }

            $seat = $follow['turn'];
            $plays = self::options(DominoRules::plays($hands[$seat], $ends, []));
            ['tile' => $tile, 'end' => $end] = $plays[self::ranked($plays, $hands[$seat], $ends)[0]];
        }
    }

    /**
     * A hand's end as the side sees it: its gain, or another side's taken
     * from it (R17–R19).
     *
     * @param  list<list<DominoTile>>  $hands
     */
    private static function worth(Domino101 $rules, RoomTable $table, RoomHandEnding $ending, int $by, array $hands, int $side): int
    {
        $count = $rules->counted($table, $ending, $by, $hands);

        return match ($count['winner']) {
            null => 0,
            $side => $count['gain'],
            default => -$count['gain'],
        };
    }

    /**
     * Each play's tile with each end listed for it, in the judge's order.
     *
     * @param  list<array{tile: DominoTile, ends: list<string>}>  $legal
     * @return list<Option>
     */
    private static function options(array $legal): array
    {
        $options = [];

        foreach ($legal as $play) {
            if ($play['ends'] === []) {
                $options[] = ['tile' => $play['tile'], 'end' => null];
            }

            foreach ($play['ends'] as $end) {
                $options[] = ['tile' => $play['tile'], 'end' => $end];
            }
        }

        return $options;
    }

    /**
     * The options' indexes in «Orta»'s order: the most pips first, of the
     * same pips a double first, then the most of its own tiles left that
     * have a number open after the play (an end counted once for each of
     * the two ends it shows); ties in the order given.
     *
     * @param  list<Option>  $options
     * @param  list<DominoTile>  $tiles  the seat's tiles before the play
     * @param  array<string, int>  $ends
     * @return list<int>
     */
    private static function ranked(array $options, array $tiles, array $ends): array
    {
        $scores = [];

        foreach ($options as $index => ['tile' => $tile, 'end' => $end]) {
            $kept = 0;

            foreach (self::after($ends, $tile, $end) as $number) {
                foreach ($tiles as $held) {
                    if ($held !== $tile && $held->has($number)) {
                        $kept++;
                    }
                }
            }

            $scores[$index] = $tile->pips() * self::PIPS + ($tile->isDouble() ? self::DOUBLE : 0) + $kept;
        }

        $order = array_keys($options);
        usort($order, static fn (int $a, int $b): int => [$scores[$b], $a] <=> [$scores[$a], $b]);

        return $order;
    }

    /**
     * The ends after a tile is put at one (R10): a hand's first tile lies
     * as its name is written (ADR 038 decision 62).
     *
     * @param  array<string, int>  $ends
     * @return array<string, int>
     */
    private static function after(array $ends, DominoTile $tile, ?string $end): array
    {
        if ($ends === []) {
            return [DominoTile::LEFT => $tile->high, DominoTile::RIGHT => $tile->low];
        }

        $at = $end ?? DominoTile::LEFT;
        $ends[$at] = $tile->other($ends[$at]);

        return $ends;
    }
}
