<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomLettersData;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomLetter;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomRound;
use App\Domain\Rooms\Support\Mechanics\AnagramOrders;

/**
 * Bought letters as the server reads them (docs/architecture.md §12
 * "«Anaqram»: a clue, a shuffle and a bought letter (ADR 037)", ADR 037
 * decisions 10–16):
 *
 * - a round sells letters when its question carries orders — an anagram
 *   round built from ADR 037 on — so the round itself says so and nothing
 *   compares a mechanic's name (`sells()`);
 * - what a player bought in a round is a count of their `room_letters`
 *   rows (`count()`): the k letters are always the drawn word's first k;
 * - the buyer's own view (`of()`): the solution's first k ids, the buyer's
 *   orders of the tiles left — drawn by `AnagramOrders` with those k taken
 *   out, from `RoomSeed::letters()`, so every read gives the same and
 *   nothing is stored — and what the next letter costs.
 */
final class RoomLetters
{
    /** Whether the round sells letters: its question carries orders. */
    public static function sells(RoomRound $round): bool
    {
        return $round->askData()->orders !== [];
    }

    /** How many letters of the round's word the player bought. */
    public static function count(RoomRound $round, RoomPlayer $row): int
    {
        return RoomLetter::query()
            ->where('room_round_id', $round->id)
            ->where('room_player_id', $row->id)
            ->count();
    }

    /** How many letters the round's word has: the places a purchase may name. */
    public static function length(RoomRound $round): int
    {
        return count($round->solutionData()->ids);
    }

    /**
     * The buyer's view of a round that sells letters, with `$bought` of
     * them bought: the bought tiles, the orders of those left — `[]` with
     * none bought (the question's serve) and with none left — and the next
     * letter's price, null once none is left.
     */
    public static function of(RoomGame $game, RoomRound $round, int $bought): RoomLettersData
    {
        $solution = $round->solutionData();
        $length = count($solution->ids);
        $bought = max(0, min($bought, $length));
        $orders = [];

        if ($bought > 0 && $bought < $length) {
            $items = [];

            foreach ($round->askData()->items as $item) {
                $items[$item->id] = $item;
            }

            $word = array_map(static fn (string $id): RoomItemData => $items[$id], $solution->ids);
            $orders = array_map(
                static fn (array $order): array => array_map(static fn (RoomItemData $tile): string => $tile->id, $order),
                AnagramOrders::draw($word, $solution->accept, $bought, RoomSeed::letters($game->seed, $round->position, $bought)),
            );
        }

        return new RoomLettersData(
            bought: array_slice($solution->ids, 0, $bought),
            orders: $orders,
            price: $bought < $length ? RoomPointsRule::letterPrice($bought) : null,
        );
    }
}
