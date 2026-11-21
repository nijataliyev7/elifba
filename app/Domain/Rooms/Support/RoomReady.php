<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomPlayer;

/**
 * Whether a player's «Hazıram» counts (docs/architecture.md §12 "Ready for
 * the next game", ADR 035 decision 5). The row keeps the position of the
 * game the player said it for (`room_players.ready_for`); it counts while
 * the room is open, the row is present and not the host's, and that is
 * still the room's next game. So a start takes every mark down without
 * writing — the next game's position moves past them — and while a game
 * runs none counts. Nothing here writes.
 */
final class RoomReady
{
    /**
     * The room's next game's position: the latest game's + 1, or 0 before
     * the first. The caller passes the latest game it already read.
     */
    public static function next(?RoomGame $latest): int
    {
        return $latest === null ? 0 : $latest->position + 1;
    }

    public static function counts(Room $room, RoomPlayer $row, ?RoomGame $latest): bool
    {
        return $room->isOpen()
            && $row->isPresent()
            && $row->user_id !== $room->user_id
            && $row->ready_for !== null
            && $row->ready_for === self::next($latest);
    }
}
