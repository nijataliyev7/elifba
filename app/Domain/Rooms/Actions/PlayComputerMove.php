<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Enums\RoomActKind;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomAct;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * The only writer of the computer's move in a board game
 * (docs/architecture.md §12 "The computer's seat", ADR 036 decisions
 * 8–10): an act without a player, of the kind `move` alone — the computer
 * never offers a draw, accepts one or resigns, and an offer made to it is
 * declined by its reply. `RoomActController` after an accepted act and
 * `RoomStateController` after a read hand it to `defer()`, so it runs
 * after a response and never inside one; nothing queues or schedules it.
 *
 * 1. Outside any lock it asks the game's driver whether the computer is to
 *    move (`RoomGameDriver::computerTurn()`); a race, a match of two
 *    players, a match that is over and a move due more than
 *    `PATIENCE_MS` from now — a countdown just begun — are left alone, the
 *    last to a later read.
 * 2. One search per game and ply at a time (`Cache::lock`): a second read
 *    that finds the same turn does nothing.
 * 3. It chooses (`computerChoice()`: one of the judge's moves) and waits
 *    until the move is due — `elifba.rooms.computer.think_ms` after the
 *    computer's turn began — so a page draws the player's own move first.
 * 4. In one transaction under the room row's lock (`RoomLock`) it asks
 *    again, and stores the move only if the running game is the same and
 *    still stands at that ply with the computer to move and its move due:
 *    timed by the server's moment under the lock, as every act is; the
 *    room is active, its revision rises and `room.changed` goes out after
 *    the commit. Anything else writes nothing.
 */
final readonly class PlayComputerMove
{
    /** A move due later than this is left to a later read. */
    private const int PATIENCE_MS = 5_000;

    /** How long one search holds its game and ply. */
    private const int HELD_SECONDS = 10;

    public function handle(Room $room): void
    {
        $nowMs = RoomClock::nowMs();
        $room = $room->fresh();
        $game = $room !== null && $room->isOpen() ? $room->runningGame($nowMs) : null;

        if ($room === null || $game === null) {
            return;
        }

        $driver = RoomGameDriver::of($game->mechanic);
        $turn = $driver->computerTurn($game, $nowMs);

        if ($turn === null || $turn['due_ms'] - $nowMs > self::PATIENCE_MS) {
            return;
        }

        $held = Cache::lock('room-computer:'.$game->id.':'.$turn['ply'], self::HELD_SECONDS);

        if (! $held->get()) {
            return;
        }

        try {
            $path = $driver->computerChoice($game, $nowMs);
            $waitMs = $turn['due_ms'] - RoomClock::nowMs();

            if ($path === null) {
                return;
            }

            if ($waitMs > 0) {
                Sleep::for($waitMs)->milliseconds();
            }

            self::store($room, $game->id, $turn['ply'], $path);
        } finally {
            $held->release();
        }
    }

    /**
     * @param  list<string>  $path
     */
    private static function store(Room $room, string $gameId, int $ply, array $path): void
    {
        DB::transaction(static function () use ($room, $gameId, $ply, $path): void {
            $locked = RoomLock::room($room);
            $nowMs = RoomClock::nowMs();
            $game = $locked !== null && $locked->isOpen() ? $locked->runningGame($nowMs) : null;

            if ($locked === null || $game === null || $game->id !== $gameId) {
                return;
            }

            $turn = RoomGameDriver::of($game->mechanic)->computerTurn($game, $nowMs);

            if ($turn === null || $turn['ply'] !== $ply || $nowMs < $turn['due_ms']) {
                return;
            }

            $stored = new RoomAct;
            $stored->room_game_id = $game->id;
            $stored->room_player_id = null;
            $stored->kind = RoomActKind::Move;
            $stored->ply = $ply;
            $stored->path = $path;
            $stored->acted_ms = $nowMs;
            $stored->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);
        });
    }
}
