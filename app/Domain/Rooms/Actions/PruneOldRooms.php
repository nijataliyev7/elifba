<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomMatchTaunt;
use App\Domain\Rooms\Models\RoomTaunt;
use App\Domain\Rooms\Support\RoomClock;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

/**
 * Deletes the rooms created longer than `elifba.retention_days` ago
 * (docs/architecture.md §1 "Scheduled", §12 "Scoring and what is kept",
 * ADR 022 decision 6), with everything under them by cascade — their
 * taunts too. From T2 it also forgets whom a taunt was aimed at once the
 * throw is older than `elifba.rooms.taunts.forget_target_hours` (§12
 * "Taunts (T2)", ADR 029 decision 31): only the target's phone ever needed
 * it; the thrower and the moment, which the cost needs, go with the room.
 * Scheduled daily (`rooms:prune` in routes/console.php).
 */
final readonly class PruneOldRooms
{
    /**
     * @return int how many rooms were deleted
     */
    public function handle(): int
    {
        $cutoff = now()->subDays(Config::integer('elifba.retention_days'));
        $forgetBeforeMs = RoomClock::nowMs() - Config::integer('elifba.rooms.taunts.forget_target_hours') * 3_600_000;

        return DB::transaction(static function () use ($cutoff, $forgetBeforeMs): int {
            RoomTaunt::query()
                ->whereNotNull('target_player_id')
                ->where('sent_ms', '<', $forgetBeforeMs)
                ->update(['target_player_id' => null]);
            RoomMatchTaunt::query()
                ->whereNotNull('target_player_id')
                ->where('sent_ms', '<', $forgetBeforeMs)
                ->update(['target_player_id' => null]);

            return Room::query()->where('created_at', '<', $cutoff)->delete();
        });
    }
}
