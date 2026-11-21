<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Support\RoomWords;
use App\Domain\Rooms\Data\RoomItemData;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * The orders an anagram lays its tiles out in (docs/architecture.md §12
 * "«Anaqram»: a clue, a shuffle and a bought letter (ADR 037)", ADR 037
 * decisions 5–9 and 16). A page never shuffles — it does not know the
 * word, so it would now and then spell it — and the server never shuffles
 * on demand: the one order it never returned would be the word. So a round
 * carries a bounded list, drawn here from the game's keyed source:
 *
 * - every order lays out every tile left once, and none reads an excluded
 *   spelling — with nothing bought, an accepted spelling of the word
 *   (`RoomWords::accepted()`); with the word's first k tiles bought, the
 *   rest of an accepted spelling that starts with the bought letters;
 * - no two orders read alike, so every «Qarışdır» changes what the row
 *   reads;
 * - each is drawn uniformly among the orders that read neither an excluded
 *   spelling nor an order already drawn: a shuffle that does is drawn
 *   again, and after `TRIES` such shuffles in a row the first such order
 *   by the letters' bytes is taken (letters nearly all of whose orders are
 *   excluded);
 * - there are `count()` of them: at most a third of the orders the letters
 *   have and at most `MAX`, so the orders never shown always outnumber
 *   those shown at least two to one and the word hides among them.
 *
 * The question's own list (k = 0) is `ask.orders`, made with the round;
 * part 2's buyer gets a list of its own for each k, drawn after the
 * purchase, since a list that also kept every k in mind would show the
 * word's last two letters reversed in every order (ADR 037 decision 16).
 */
final class AnagramOrders
{
    /**
     * The most orders a list holds: a round lasts 20–50 s, and six
     * arrangements are more than a player presses through.
     */
    public const int MAX = 6;

    /**
     * A list shows at most one order in this many of the letters' orders:
     * «baba» has six, and its list shows two.
     */
    public const int SHARE = 3;

    /**
     * Shuffles in a row that may read an excluded or a drawn order before
     * the orders are walked from the first.
     */
    private const int TRIES = 64;

    /**
     * How many orders a list holds for letters with `$arrangements`
     * different orders, `$excluded` of them excluded:
     * max(1, min(MAX, ⌊arrangements / SHARE⌋, arrangements − excluded)).
     */
    public static function count(int $arrangements, int $excluded): int
    {
        return max(1, min(self::MAX, intdiv($arrangements, self::SHARE), $arrangements - $excluded));
    }

    /**
     * The orders of the tiles left once the word's first `$bought` tiles
     * are taken out; with nothing bought, the question's list, whose first
     * order is the question's `items`. Tiles that have one order only —
     * one letter, or one letter repeated — come back as they are.
     *
     * @param  list<RoomItemData>  $word  the round's tiles in the drawn word's order (the solution's `ids`)
     * @param  list<list<string>>  $accept  every accepted spelling, read in the tiles' capitals
     * @param  int  $bought  how many of the word's first tiles are taken out: 0 for the question
     * @return list<list<RoomItemData>>
     *
     * @throws InvalidArgumentException when every order of the tiles left reads an excluded spelling
     */
    public static function draw(array $word, array $accept, int $bought, Randomizer $random): array
    {
        if ($bought < 0 || $bought > count($word)) {
            throw new InvalidArgumentException('A word of '.count($word)." letters has no {$bought} letters to take out.");
        }

        $left = array_slice($word, $bought);
        $arrangements = RoomWords::arrangements(self::key($left));

        if ($arrangements < 2) {
            return [$left];
        }

        $prefix = self::reading(array_slice($word, 0, $bought));
        /** @var array<string, true> $taken the readings no order may have: the excluded, then the drawn */
        $taken = [];

        foreach ($accept as $spelling) {
            if (array_slice($spelling, 0, $bought) === $prefix) {
                $taken[implode('', array_slice($spelling, $bought))] = true;
            }
        }

        $count = self::count($arrangements, count($taken));
        $orders = [];

        while (count($orders) < $count) {
            $order = self::next($left, $taken, $random);
            $taken[self::key($order)] = true;
            $orders[] = $order;
        }

        return $orders;
    }

    /**
     * A shuffle of the tiles whose reading is not taken, or after `TRIES`
     * that all are, the first order of their letters by bytes that is not.
     *
     * @param  list<RoomItemData>  $tiles
     * @param  array<string, true>  $taken
     * @return list<RoomItemData>
     */
    private static function next(array $tiles, array $taken, Randomizer $random): array
    {
        for ($try = 0; $try < self::TRIES; $try++) {
            /** @var list<RoomItemData> $shuffled */
            $shuffled = $random->shuffleArray($tiles);

            if (! isset($taken[self::key($shuffled)])) {
                return $shuffled;
            }
        }

        $reading = self::reading($tiles);
        sort($reading, SORT_STRING);

        do {
            if (! isset($taken[implode('', $reading)])) {
                return self::laidOut($tiles, $reading);
            }
        } while (($reading = self::nextOrder($reading)) !== null);

        throw new InvalidArgumentException('These letters read an excluded spelling in every order.');
    }

    /**
     * The tiles laid out to read `$reading`, each letter's tiles in their
     * order.
     *
     * @param  list<RoomItemData>  $tiles
     * @param  list<string>  $reading
     * @return list<RoomItemData>
     */
    private static function laidOut(array $tiles, array $reading): array
    {
        $left = $tiles;
        $laid = [];

        foreach ($reading as $letter) {
            foreach ($left as $index => $tile) {
                if ($tile->says() === $letter) {
                    $laid[] = $tile;
                    unset($left[$index]);

                    break;
                }
            }
        }

        return $laid;
    }

    /**
     * The next order of the letters in lexicographic order, or null after
     * the last: each different order once, however often a letter comes.
     *
     * @param  list<string>  $letters
     * @return list<string>|null
     */
    private static function nextOrder(array $letters): ?array
    {
        $pivot = count($letters) - 2;

        while ($pivot >= 0 && strcmp($letters[$pivot], $letters[$pivot + 1]) >= 0) {
            $pivot--;
        }

        if ($pivot < 0) {
            return null;
        }

        $swap = count($letters) - 1;

        while (strcmp($letters[$swap], $letters[$pivot]) <= 0) {
            $swap--;
        }

        [$letters[$pivot], $letters[$swap]] = [$letters[$swap], $letters[$pivot]];

        return [...array_slice($letters, 0, $pivot + 1), ...array_reverse(array_slice($letters, $pivot + 1))];
    }

    /**
     * What the tiles read, a letter each.
     *
     * @param  list<RoomItemData>  $tiles
     * @return list<string>
     */
    private static function reading(array $tiles): array
    {
        return array_map(static fn (RoomItemData $tile): string => $tile->says(), $tiles);
    }

    /**
     * What the tiles read, as one string: a tile is one letter, so two
     * orders read alike exactly when their keys are equal.
     *
     * @param  list<RoomItemData>  $tiles
     */
    private static function key(array $tiles): string
    {
        return implode('', self::reading($tiles));
    }
}
