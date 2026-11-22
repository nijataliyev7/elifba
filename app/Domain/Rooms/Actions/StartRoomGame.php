<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomGame;
use App\Domain\Rooms\Support\Drivers\RoomGameDriver;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The host starts a game (docs/architecture.md §12 "The race", "Games
 * first", ADRs 021 and 026 decision 9): under the room row's lock it
 * refuses a closed room and a room whose game is still running —
 * `RoomPolicy::start` refused them already, and two starts at once meet
 * here — then starts what the room's choice says (`Room::setup()`, read
 * under the lock; the request has no body), so what starts is what every
 * device was shown, and «Yenidən oyna» is the same start again. It builds
 * every round with the mechanic's builder from a fresh seed (`RoomSeed`),
 * stores each as its `payload` and its `solution`, and sets round 0 to
 * open `elifba.rooms.countdown_ms` from now, with the configured
 * `reveal_ms` kept as the game's. The choice stays as it is. The room is
 * active, its revision rises and it is signalled.
 *
 * What a start does beyond the game's row is its kind's (§12 "Board
 * games", ADR 034 decisions 3 and 8; `RoomGameDriver::start()`): a race
 * builds its rounds as above; a board game seats its two players and copies
 * its level's clock — `started_ms` is then when its clock starts — refuses
 * a choice with fewer than two seats (`rooms.errors.needs_two`) or, against
 * the computer, with none (`rooms.errors.needs_one`), and turns the room's
 * seats round for the next game. A room of one plays the computer with no
 * choosing (`RoomSeats`, ADR 036 as amended 2026-11-22).
 */
final readonly class StartRoomGame
{
    public function __construct(private ContentRepository $content) {}

    /**
     * @throws AuthorizationException when the room is closed or a game is running in it
     * @throws ValidationException when the choice cannot start: a board game with fewer than two seats
     */
    public function handle(Room $room): RoomGame
    {
        return DB::transaction(function () use ($room): RoomGame {
            $locked = RoomLock::room($room);
            $nowMs = RoomClock::nowMs();

            if ($locked === null || ! $locked->isOpen() || $locked->runningGame($nowMs) !== null) {
                throw new AuthorizationException;
            }

            $setup = $locked->setup();

            $game = new RoomGame;
            $game->room_id = $locked->id;
            $game->position = $locked->games()->count();
            $game->mechanic = $setup->mechanic;
            $game->difficulty = $setup->difficulty;
            $game->content_version = $this->content->roomVersion();
            $game->started_ms = $nowMs + Config::integer('elifba.rooms.countdown_ms');
            RoomGameDriver::of($setup->mechanic)->start($locked, $game, $setup);

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $game;
        });
    }
}
