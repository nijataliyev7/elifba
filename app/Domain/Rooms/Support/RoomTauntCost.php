<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;

/**
 * When a taunt's cost counts (docs/architecture.md §12 "Taunts (T2)", ADR
 * 029 decision 2): from the first close of the game after the throw —
 * thrown in round i's countdown, study or answer, at `closes(i)`; thrown in
 * round i's reveal, at `closes(i + 1)` — and every taunt of a game once
 * the game is over (its last close, the host's end, the room's close). So
 * the ranking, which `RoomRanking` derives with it, still changes only at
 * a close, and the cost is told at the reveal where everything else of the
 * round is. A pure reading of the timetable and the taunts' moments; it
 * writes nothing and stores nothing.
 *
 * That is a race's: `countsAt()`, `counted()` and `spentAt()` read its
 * timetable. A board game has one close, its end, where every taunt of it
 * counts (ADR 034 decision 22); `spent()` asks the game's driver when
 * a taunt counts (`RoomGameDriver::tauntCounts()`).
 */
final class RoomTauntCost
{
    /**
     * The position of the round at whose close a taunt thrown at `$sentMs`
     * counts: the first close strictly after it, since a close's own moment
     * is already its reveal; null when none of the game's closes comes after
     * it.
     */
    public static function countsAt(RoomTimetable $timetable, int $sentMs): ?int
    {
        for ($round = 0; $round < $timetable->count(); $round++) {
            if ($timetable->closes($round) > $sentMs) {
                return $round;
            }
        }

        return null;
    }

    /**
     * Whether a taunt thrown at `$sentMs` counts at `$nowMs`.
     */
    public static function counted(RoomTimetable $timetable, int $sentMs, int $nowMs): bool
    {
        if ($nowMs >= $timetable->overMs()) {
            return true;
        }

        $round = self::countsAt($timetable, $sentMs);

        return $round !== null && $timetable->closes($round) <= $nowMs;
    }

    /**
     * The taunts counted at `$nowMs`, by the thrower's row, as the game's
     * driver counts them.
     *
     * @param  iterable<RoomTaunt>|null  $taunts  the game's taunts; read when null
     * @return array<string, int>
     */
    public static function spent(RoomGame $game, int $nowMs, ?iterable $taunts = null): array
    {
        $counts = RoomGameDriver::of($game->mechanic)->tauntCounts($game, $nowMs);
        $spent = [];

        foreach ($taunts ?? self::of($game) as $taunt) {
            if ($counts($taunt->sent_ms)) {
                $spent[$taunt->room_player_id] = ($spent[$taunt->room_player_id] ?? 0) + 1;
            }
        }

        return $spent;
    }

    /**
     * The taunts counted at round `$index`'s close, by the thrower's row:
     * the reveal's `spent`.
     *
     * @return array<string, int>
     */
    public static function spentAt(RoomGame $game, int $index): array
    {
        $timetable = $game->timetable();
        $spent = [];

        foreach (self::of($game) as $taunt) {
            if (self::countsAt($timetable, $taunt->sent_ms) === $index) {
                $spent[$taunt->room_player_id] = ($spent[$taunt->room_player_id] ?? 0) + 1;
            }
        }

        return $spent;
    }

    /**
     * @return iterable<RoomTaunt>
     */
    private static function of(RoomGame $game): iterable
    {
        return RoomTaunt::query()->where('room_game_id', $game->id)->get(['room_player_id', 'sent_ms']);
    }
}
