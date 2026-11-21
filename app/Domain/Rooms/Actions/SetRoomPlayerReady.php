<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\RoomReadyData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomReady;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * A player's «Hazıram» between games, or taking it back
 * (docs/architecture.md §12 "Ready for the next game", ADR 035 decision 4):
 * `PUT /rooms/{room}/ready` `{on}`, the player being the session's
 * account. Under the room row's lock it refuses a closed room, a row that
 * is not present, the host's row and a game running — `RoomPolicy::ready`
 * refused them already, and a start at the same moment meets here — then
 * sets `ready_for` to the room's next game's position, or clears it. What
 * already counts, or already does not, writes nothing and signals nothing.
 * A change marks the room active, raises its revision and signals it:
 * every device, the tapping one too, learns it from the state. It never
 * starts, holds or changes a game.
 */
final readonly class SetRoomPlayerReady
{
    /**
     * @throws AuthorizationException when the room is closed, the account is not a present player who is not the host, or a game is running
     */
    public function handle(Room $room, User $account, RoomReadyData $data): void
    {
        DB::transaction(static function () use ($room, $account, $data): void {
            $locked = RoomLock::room($room);
            $row = $locked?->players()->where('user_id', $account->id)->first();

            if ($locked === null || $row === null || ! $locked->isOpen() || ! $row->isPresent()
                || $locked->isHostedBy($account) || $locked->runningGame(RoomClock::nowMs()) !== null) {
                throw new AuthorizationException;
            }

            $latest = $locked->latestGame();

            if (RoomReady::counts($locked, $row, $latest) === $data->on) {
                return;
            }

            $row->ready_for = $data->on ? RoomReady::next($latest) : null;
            $row->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);
        });
    }
}
