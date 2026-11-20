<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\OpenRoomData;
use App\Domain\Rooms\Exceptions\RoomGameRunning;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomPlayer;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomCode;
use App\Domain\Rooms\Support\RoomDefaults;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A game chosen on «Oyunlar» (docs/architecture.md §12 "Rooms and joining",
 * "Games first", ADRs 020 and 026 decision 7). One open room per host,
 * checked under the lock on the host's `users` row; an idle room no longer
 * counts.
 *
 * - The account hosts no open room: a `rooms` row with a code from
 *   `RoomCode` and the choice — this game at the host's defaults
 *   (`RoomDefaults`) — and the host as its first player. While the
 *   instance's live voice is on (`$voice`, which the controller reads
 *   from `RoomsArea::voice()`, as `RoomState::for()` is told it), the
 *   room's voice is on from this moment and the host's row is let into
 *   it (ADR 031 decision 37, as amended 2026-11-19). No call goes to the
 *   voice server: the first ticket makes the voice room.
 * - It hosts an open room with no game running: no second room. That
 *   room's choice takes this game and keeps its difficulty and rounds
 *   (the game's defaults when it has no level at that difficulty), under
 *   the room row's lock; the room is active, its revision rises and it is
 *   signalled. Its voice stays as it is.
 * - It hosts an open room with a game running: nothing is written
 *   (`RoomGameRunning`).
 *
 * A board game (ADR 034 decision 2) is the room's `board`: a new room is
 * opened for it, and the host's open room turns to it — or back to the
 * round games when a round game is chosen — unless a game or a match is
 * running there.
 */
final readonly class OpenRoom
{
    public function __construct(private RoomCode $codes) {}

    /**
     * @param  bool  $voice  whether the instance's live voice is on
     *
     * @throws RoomGameRunning when a game is running in the host's open room
     */
    public function handle(User $host, OpenRoomData $data, bool $voice = false): Room
    {
        return DB::transaction(function () use ($host, $data, $voice): Room {
            RoomLock::host($host->id);

            $open = Room::query()->open()->where('user_id', $host->id)->first();

            if ($open !== null) {
                return self::choose($open, $data);
            }

            $setup = RoomDefaults::for($host, $data->mechanic);

            $room = $this->codes->issue(static fn (string $code): Room => DB::transaction(static function () use ($host, $code, $setup, $voice, $data): Room {
                $room = new Room;
                $room->user_id = $host->id;
                $room->code = $code;
                $room->setup_mechanic = $setup->mechanic;
                $room->setup_difficulty = $setup->difficulty;
                $room->setup_rounds = $setup->rounds;
                $room->board = $data->board;
                $room->last_activity_at = now();
                $room->voice_at = $voice ? now() : null;
                $room->save();

                return $room;
            }));

            $player = new RoomPlayer;
            $player->room_id = $room->id;
            $player->user_id = $host->id;
            $player->joined_at = now();
            $player->voice_admitted_at = $voice ? now() : null;
            $player->save();

            return $room;
        });
    }

    /**
     * The host's open room takes the game, unless one is running.
     *
     * @throws RoomGameRunning
     */
    private static function choose(Room $open, OpenRoomData $data): Room
    {
        $locked = RoomLock::room($open) ?? $open;

        if ($locked->runningGame(RoomClock::nowMs()) !== null || $locked->runningMatch() !== null) {
            throw new RoomGameRunning($locked);
        }

        $locked->board = $data->board;

        if ($data->board !== null) {
            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $locked;
        }

        $setup = $locked->setup()->withMechanic($data->mechanic) ?? RoomDefaults::for($locked->host, $data->mechanic);
        $locked->setup_mechanic = $setup->mechanic;
        $locked->setup_difficulty = $setup->difficulty;
        $locked->setup_rounds = $setup->rounds;
        $locked->last_activity_at = now();
        RoomSignal::raise($locked);

        return $locked;
    }
}
