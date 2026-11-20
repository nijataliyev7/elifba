<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;

/**
 * What a player may spend on taunts now (docs/architecture.md §12 "Taunts
 * (T2)", ADR 029 decisions 3–5): the points of the player's answers over
 * the game's closed rounds (`RoomRanking::closedRounds()`) less
 * `RoomPointsRule::TAUNT_COST` for every taunt the player threw in this
 * game, counted yet or not — derived at each request, never stored, never
 * below 0. Only the running game's points: the room's standing from
 * earlier games is not a balance.
 *
 * It reads the closed rounds and the player's own taunts, never the open
 * round's answers, so the −1 a thrower sees at once is the same whatever
 * anybody answered; and every balance is 0 until the first round closes.
 * `ThrowRoomTaunt` reads it under the room row's lock, so one point is
 * never spent twice.
 *
 * What the points are is the game's driver's
 * (`RoomGameDriver::tauntBudget()`): a race's closed rounds as above; in a
 * board game a seat's is what the match can still pay — `MATCH_WIN` — and
 * a watcher's nothing (ADR 034 decision 22).
 */
final class RoomTauntBalance
{
    public static function of(RoomGame $game, RoomPlayer $row, int $nowMs): int
    {
        $thrown = RoomTaunt::query()
            ->where('room_game_id', $game->id)
            ->where('room_player_id', $row->id)
            ->count();

        return max(0, RoomGameDriver::of($game->mechanic)->tauntBudget($game, $row, $nowMs) - RoomPointsRule::TAUNT_COST * $thrown);
    }
}
