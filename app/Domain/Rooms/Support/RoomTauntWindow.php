<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;

/**
 * When a taunt may be thrown (docs/architecture.md §12 "Taunts (T2)", ADR
 * 029 decision 10): in an open room, from its game's start — the countdown
 * included — until the game's last round closes: every countdown, study,
 * answer and reveal but the last. Never in the lobby, the start screen, the
 * last reveal, the final or a closed room — a taunt in the last reveal
 * would count after the last close and change the final the room is about
 * to see. The host's switch, the balance and the cooldown are
 * `ThrowRoomTaunt`'s to check.
 *
 * That is a race's (`RaceDriver::tauntsOpen()`); a board game's window is
 * the live match, its countdown included, and only its two seats throw
 * (ADR 034 decision 22) — each as the game's driver says.
 */
final class RoomTauntWindow
{
    /**
     * Whether `$thrower` — a present player — may throw a taunt now.
     *
     * @param  RoomGame|null  $game  the room's latest game, with its rounds
     */
    public static function open(Room $room, ?RoomGame $game, RoomPlayer $thrower, int $nowMs): bool
    {
        if ($game === null || ! $room->isOpen()) {
            return false;
        }

        $driver = RoomGameDriver::of($game->mechanic);

        return $driver->tauntsOpen($game, $nowMs) && $driver->mayThrow($game, $thrower);
    }
}
