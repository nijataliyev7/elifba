<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomRoundClose;
use App\Domain\Rooms\Support\RoomSignal;
use App\Domain\Rooms\Support\RoomVoice;
use App\Domain\Rooms\Support\RoomVoiceCut;
use Illuminate\Support\Facades\DB;

/**
 * A player leaves an open room (docs/architecture.md §12 "Rooms and
 * joining"): the row stays, with `left_at`, and the same account may come
 * back with the code to it. A round open for answers that everyone it
 * still waits for has answered closes now (`RoomRoundClose`): it never
 * waits for someone who left. Raises the revision and signals the room.
 *
 * While the room's voice is on, a player who leaves is then taken out of
 * the voice room (`RoomVoiceCut::remove()`, §12 "Live voice", ADR 031
 * decision 22); what the host decided about their voice stays on the row
 * for a return. The host keeps the voice: the host's row is never taken
 * out.
 */
final readonly class LeaveRoom
{
    public function handle(Room $room, RoomPlayer $player): void
    {
        DB::transaction(static function () use ($room, $player): void {
            $locked = RoomLock::room($room);
            $row = $locked?->players()->whereKey($player->id)->first();

            if ($locked === null || $row === null || ! $row->isPresent()) {
                return;
            }

            $row->left_at = now();
            $row->save();

            RoomRoundClose::whenPresenceChanges($locked, RoomClock::nowMs());
            EndRoomMatch::whenPresenceChanges($locked, $row);
            RoomSignal::raise($locked);

            if ($locked->voiceIsOn() && ! RoomVoice::isHost($locked, $row)) {
                RoomVoiceCut::remove($locked, $row);
            }
        });
    }
}
