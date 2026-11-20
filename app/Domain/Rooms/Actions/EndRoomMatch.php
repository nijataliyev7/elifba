<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Enums\RoomMatchEnding;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Support\Facades\DB;

/**
 * A board match ends before its position does (docs/architecture.md §12
 * "Board games", ADR 034 decision 6): a player resigns — the other side
 * wins — or the host ends it with no result. `whenPresenceChanges()` is
 * called by the actions that end a presence, under the room row's lock: a
 * player of the running match who leaves or is removed loses it.
 */
final readonly class EndRoomMatch
{
    /**
     * The host ends the running match: no winner.
     */
    public function byHost(Room $room): void
    {
        DB::transaction(static function () use ($room): void {
            $locked = RoomLock::room($room);
            $match = $locked?->runningMatch();

            if ($locked === null || $match === null) {
                return;
            }

            $match->ended_ms = RoomClock::nowMs();
            $match->ending = RoomMatchEnding::Host;
            $match->save();

            RoomSignal::raise($locked);
        });
    }

    /**
     * A player of the running match gives up: the other side wins.
     */
    public function resign(Room $room, RoomPlayer $player): void
    {
        DB::transaction(static function () use ($room, $player): void {
            $locked = RoomLock::room($room);

            if ($locked !== null && self::lose($locked, $player->id, RoomMatchEnding::Resign)) {
                $locked->last_activity_at = now();
                RoomSignal::raise($locked);
            }
        });
    }

    /**
     * A player left the room or was removed: if they play the running
     * match, they lose it. The caller holds the room row locked and
     * raises its revision.
     */
    public static function whenPresenceChanges(Room $locked, RoomPlayer $player): void
    {
        self::lose($locked, $player->id, RoomMatchEnding::Left);
    }

    /**
     * The room closes under a running match: it ends with no result. The
     * caller holds the room row locked and raises its revision.
     */
    public static function whenClosed(Room $locked): void
    {
        $match = $locked->runningMatch();

        if ($match !== null) {
            $match->ended_ms = RoomClock::nowMs();
            $match->ending = RoomMatchEnding::Host;
            $match->save();
        }
    }

    private static function lose(Room $locked, string $playerId, RoomMatchEnding $ending): bool
    {
        $match = $locked->runningMatch();
        $side = $match?->sideOf($playerId);

        if ($match === null || $side === null) {
            return false;
        }

        $match->ended_ms = RoomClock::nowMs();
        $match->result = $side === 'w' ? 'b' : 'w';
        $match->ending = $ending;
        $match->save();

        return true;
    }
}
