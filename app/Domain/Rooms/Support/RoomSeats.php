<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Models\Room;

/**
 * The two seats of the room's next board game (docs/architecture.md §12
 * "Board games", ADR 034 decision 7): a seat the host stored
 * (`rooms.setup_white_id`, `setup_black_id`) while its player is present;
 * otherwise the present player who joined first and has no seat, white
 * filled before black — so a room of two needs no choosing, and a seat
 * whose player left is taken by the next. Null while the room has nobody
 * more to seat. With the computer playing (K3, ADR 036 decision 2) there
 * is one seat — the stored white while its player is present, else the
 * stored black, else the present player who joined first, as white — and
 * the other colour is the computer's.
 *
 * The computer plays when it was chosen (`rooms.setup_computer`) and —
 * nobody having chosen it — while the room has fewer than two present
 * players, at «Orta» (`ALONE`; ADR 036 as amended 2026-11-22): a room of
 * one starts against it with no tap, and a second player takes its seat
 * by joining. Derived at each read from one list of the present players,
 * never written here.
 */
final class RoomSeats
{
    /** The computer's level in a room of fewer than two players that chose none: «Orta». */
    public const RoomComputerLevel ALONE = RoomComputerLevel::Medium;

    /**
     * @return array{0: string|null, 1: string|null, 2: RoomComputerLevel|null} white's and black's `room_players.id`, and the computer's level while it plays the colour without one
     */
    public static function of(Room $room): array
    {
        /** @var list<string> $present */
        $present = $room->players()->present()->orderBy('joined_at')->orderBy('id')->pluck('id')->all();
        $computer = self::level($room, count($present));

        $white = in_array($room->setup_white_id, $present, true) ? $room->setup_white_id : null;
        $black = in_array($room->setup_black_id, $present, true) && $room->setup_black_id !== $white ? $room->setup_black_id : null;
        // The computer plays (ADR 036 decision 2): one seat, the stored white before the stored black, else whoever joined first.
        if ($computer !== null) {
            return match (true) {
                $white !== null => [$white, null, $computer],
                $black !== null => [null, $black, $computer],
                default => [$present[0] ?? null, null, $computer],
            };
        }

        $free = array_values(array_filter($present, static fn (string $id): bool => $id !== $white && $id !== $black));

        $white ??= array_shift($free);
        $black ??= array_shift($free);

        return [$white, $black, null];
    }

    /**
     * The computer's level in the room's next board game, as `of()` derives
     * it: the one chosen, else `ALONE` while fewer than two players are
     * present, else null — two players.
     */
    public static function computer(Room $room): ?RoomComputerLevel
    {
        return $room->setup_computer ?? self::level($room, $room->players()->present()->count());
    }

    private static function level(Room $room, int $present): ?RoomComputerLevel
    {
        return $room->setup_computer ?? ($present < 2 ? self::ALONE : null);
    }
}
