<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomReady;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Brings an account into a room with its code (docs/architecture.md §12
 * "Rooms and joining", ADR 020 decision 4). Under the room row's lock: a
 * closed room and an account the host removed are not found — refused as
 * an unknown code is; a present player stays as they are; a locked and
 * a full room refuse (on `code`, with nothing else about the room); a
 * player who left comes back to the same row; anyone else gets a new row.
 * A change raises the revision, marks the room active and signals it; a
 * player who had answered the open round and comes back alone to it
 * closes it (`RoomRoundClose`).
 *
 * While the room's voice is on, a row that comes in with no admission
 * while no game is running — the start screen, between games, the final —
 * is let into the voice at once: the host is looking at the list as it
 * grows. One that comes in while a game runs waits for «Səsə burax»; a
 * player who returns keeps what their row had (docs/architecture.md §12
 * "Live voice", ADR 031 decision 39, as amended 2026-11-19). An admission
 * calls no voice server.
 *
 * A row it brings in between games — new, or back after leaving — is
 * ready for the room's next game (`ready_for`, ADR 035 as amended
 * 2026-11-21): joining is the wish to play it, and the player can take it
 * back. A join while a game runs marks nothing; the host is never marked.
 */
final readonly class JoinRoom
{
    /**
     * @throws ModelNotFoundException<Room> when the room is closed or the account was removed from it
     * @throws ValidationException on `code` when the room is locked or full
     */
    public function handle(Room $room, User $account): RoomPlayer
    {
        return DB::transaction(static function () use ($room, $account): RoomPlayer {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen()) {
                throw (new ModelNotFoundException)->setModel(Room::class);
            }

            $row = $locked->players()->where('user_id', $account->id)->first();

            if ($row?->removed_at !== null) {
                throw (new ModelNotFoundException)->setModel(Room::class);
            }

            if ($row?->isPresent()) {
                return $row;
            }

            if ($locked->locked_at !== null) {
                throw ValidationException::withMessages(['code' => __('rooms.errors.locked')]);
            }

            if ($locked->players()->present()->count() >= Config::integer('elifba.rooms.max_players')) {
                throw ValidationException::withMessages(['code' => __('rooms.errors.full')]);
            }

            if ($row === null) {
                $row = new RoomPlayer;
                $row->room_id = $locked->id;
                $row->user_id = $account->id;
                $row->joined_at = now();
            }

            $nowMs = RoomClock::nowMs();
            $row->left_at = null;
            $between = $locked->runningGame($nowMs) === null;

            if ($row->voice_admitted_at === null && $locked->voiceIsOn() && $between) {
                $row->voice_admitted_at = now();
            }

            // Ready by default for the next game (ADR 035 decision 21): not the host's row, not during a game.
            if ($between && ! $locked->isHostedBy($account)) {
                $row->ready_for = RoomReady::next($locked->latestGame());
            }

            $row->save();
            RoomGameDriver::presenceChanged($locked, $row, $nowMs);

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $row;
        });
    }
}
