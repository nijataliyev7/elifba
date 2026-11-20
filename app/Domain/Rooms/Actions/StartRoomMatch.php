<?php

namespace App\Domain\Rooms\Actions;

use App\Domain\Rooms\Data\StartRoomMatchData;
use App\Domain\Rooms\Models\Room;
use App\Domain\Rooms\Models\RoomMatch;
use App\Domain\Rooms\Support\Checkers;
use App\Domain\Rooms\Support\RoomClock;
use App\Domain\Rooms\Support\RoomLock;
use App\Domain\Rooms\Support\RoomSignal;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The host starts a board match (docs/architecture.md §12 "Board games",
 * ADR 034 decision 3): under the room row's lock it refuses a closed room,
 * a room not opened for a board game and a room whose match still runs,
 * then checks that white and black are two present players of the room
 * and sets the starting position with white to move, each side with
 * `elifba.rooms.checkers.clock_ms` on its clock and white's running. The room is active,
 * its revision rises and it is signalled.
 */
final readonly class StartRoomMatch
{
    /**
     * @throws AuthorizationException when the room is closed, has no board game or a match runs
     * @throws ValidationException when a side is not a present player of the room
     */
    public function handle(Room $room, StartRoomMatchData $data): RoomMatch
    {
        return DB::transaction(static function () use ($room, $data): RoomMatch {
            $locked = RoomLock::room($room);

            if ($locked === null || ! $locked->isOpen() || $locked->board === null || $locked->runningMatch() !== null) {
                throw new AuthorizationException;
            }

            foreach (['white' => $data->white, 'black' => $data->black] as $field => $id) {
                $row = $locked->players()->whereKey($id)->first();

                if ($row === null || ! $row->isPresent()) {
                    throw ValidationException::withMessages([$field => __('rooms.match.not_present')]);
                }
            }

            // A previous match lost on time is written now, with what ends it.
            $previous = $locked->latestMatch();

            if ($previous !== null && $previous->isDirty()) {
                $previous->save();
            }

            $match = new RoomMatch;
            $match->room_id = $locked->id;
            $match->position = $locked->matches()->count();
            $match->board_game = $locked->board;
            $match->white_player_id = $data->white;
            $match->black_player_id = $data->black;
            $match->board = Checkers::initial();
            $match->turn = 'w';
            $match->ply = 0;
            $match->quiet_plies = 0;
            $match->started_ms = RoomClock::nowMs();
            $match->white_left_ms = Config::integer('elifba.rooms.checkers.clock_ms');
            $match->black_left_ms = Config::integer('elifba.rooms.checkers.clock_ms');
            $match->turn_since_ms = $match->started_ms;
            $match->save();

            $locked->last_activity_at = now();
            RoomSignal::raise($locked);

            return $match;
        });
    }
}
