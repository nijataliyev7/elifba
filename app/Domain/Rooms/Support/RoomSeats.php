<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\Room;

/**
 * The two seats of the room's next board game (docs/architecture.md §12
 * "Board games", ADR 034 decision 7): a seat the host stored
 * (`rooms.setup_white_id`, `setup_black_id`) while its player is present;
 * otherwise the present player who joined first and has no seat, white
 * filled before black — so a room of two needs no choosing, and a seat
 * whose player left is taken by the next. Null while the room has nobody
 * more to seat. Derived at each read, never written here.
 */
final class RoomSeats
{
    /**
     * @return array{0: string|null, 1: string|null} white's and black's `room_players.id`
     */
    public static function of(Room $room): array
    {
        /** @var list<string> $present */
        $present = $room->players()->present()->orderBy('joined_at')->orderBy('id')->pluck('id')->all();

        $white = in_array($room->setup_white_id, $present, true) ? $room->setup_white_id : null;
        $black = in_array($room->setup_black_id, $present, true) && $room->setup_black_id !== $white ? $room->setup_black_id : null;
        $free = array_values(array_filter($present, static fn (string $id): bool => $id !== $white && $id !== $black));

        $white ??= array_shift($free);
        $black ??= array_shift($free);

        return [$white, $black];
    }
}
