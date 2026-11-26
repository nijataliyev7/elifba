<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Enums\RoomComputerLevel;
use App\Domain\Rooms\Enums\RoomTable;
use App\Domain\Rooms\Models\Room;

/**
 * The table and the seats of the room's next domino game (docs/architecture
 * .md §12 "Domino" → "The choice", ADR 038 decisions 11–13), derived at each
 * read from one list of the present players, as `RoomSeats` derives a
 * match's — never written here:
 *
 * - the table is the stored one (`rooms.setup_table`), else by the number
 *   of present players: `two` for fewer than three, `three` for three,
 *   `pairs` for four or more;
 * - a seat is its stored player (`rooms.setup_seats`) while present and
 *   named once; an empty place takes the present player who joined first
 *   and has no seat; a place nobody is left for is the computer's;
 * - the computer's level is `rooms.setup_computer` — the column a match's
 *   computer has — else «Orta» (`RoomSeats::ALONE`), one level for every
 *   computer seat; null when no seat is the computer's.
 *
 * So a room of one starts against the computer with no choosing, a room of
 * two is a duel, three play each for themselves and four sit as two pairs,
 * the first two present players on opposite sides; a player who joins
 * before the start takes a computer's seat by joining; with more present
 * players than seats the rest watch. A present player always sits before
 * the computer does.
 */
final class RoomTableSeats
{
    /**
     * @return array{0: RoomTable, 1: list<string|null>, 2: RoomComputerLevel|null} the table, each seat's `room_players.id` (null: the computer's) in the order of the turns, and the computer's level while a seat is its
     */
    public static function of(Room $room): array
    {
        /** @var list<string> $present */
        $present = $room->players()->present()->orderBy('joined_at')->orderBy('id')->pluck('id')->all();
        $table = $room->setup_table ?? match (true) {
            count($present) < 3 => RoomTable::Two,
            count($present) === 3 => RoomTable::Three,
            default => RoomTable::Pairs,
        };
        $stored = $room->setup_seats ?? [];
        $seats = [];

        foreach (range(0, $table->seats() - 1) as $seat) {
            $player = $stored[$seat] ?? null;
            $seats[] = $player !== null && in_array($player, $present, true) && ! in_array($player, $seats, true) ? $player : null;
        }

        $free = array_values(array_filter($present, static fn (string $id): bool => ! in_array($id, $seats, true)));

        foreach ($seats as $seat => $player) {
            $seats[$seat] = $player ?? array_shift($free);
        }

        return [$table, $seats, in_array(null, $seats, true) ? ($room->setup_computer ?? RoomSeats::ALONE) : null];
    }
}
