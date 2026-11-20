<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomRoundClose;
use App\Domain\Rooms\Support\RoomSignal;
use App\Domain\Rooms\Support\RoomVoiceCut;
use Illuminate\Support\Facades\DB;

/**
 * The host's «Çıxar» (docs/architecture.md §12 "Rooms and joining", ADR 020
 * decision 8): `removed_at` on a player's row, for good — that account no
 * longer finds the room, cannot come back with the code, and its answers
 * leave the ranking. Never the host's own row (`RoomPolicy::remove`), and
 * nothing in a closed room, an idle one included. Raises the revision and
 * signals the room. A round open for answers that everyone it still waits
 * for has answered closes now (`RoomRoundClose`).
 *
 * While the room's voice is on (§12 "Live voice", ADR 031 decision 22) the
 * removal of a player let into the voice also raises the epoch, and once it
 * is committed and signalled the voice room of the epoch before is deleted
 * (`RoomVoiceCut`): the removed player is cut at once and no old ticket
 * joins again; the others' pages come back with new tickets. A newcomer who
 * waits was never given a ticket, so removing one cuts nobody (C5).
 */
final readonly class RemoveRoomPlayer
{
    public function handle(Room $room, RoomPlayer $member): void
    {
        DB::transaction(static function () use ($room, $member): void {
            $locked = RoomLock::room($room);
            $row = $locked?->players()->whereKey($member->id)->first();

            if ($locked === null || ! $locked->isOpen() || $row === null || $row->removed_at !== null || $row->user_id === $locked->user_id) {
                return;
            }

            $row->removed_at = now();
            $row->save();

            // Only a row let into the voice can hold a ticket (decision 18); `voice_admitted_at` is never cleared.
            $voiceBefore = $locked->voiceIsOn() && $row->voice_admitted_at !== null ? $locked->voice_epoch : null;

            if ($voiceBefore !== null) {
                $locked->voice_epoch = $voiceBefore + 1;
            }

            RoomRoundClose::whenPresenceChanges($locked, RoomClock::nowMs());
            EndRoomMatch::whenPresenceChanges($locked, $row);
            RoomSignal::raise($locked);

            if ($voiceBefore !== null) {
                RoomVoiceCut::close($locked, $voiceBefore);
            }
        });
    }
}
