<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\ChooseRoomGame;
use App\Domain\Rooms\Data\RoomGameSetupData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The host changes the room's choice of the next game (docs/architecture.md
 * §12 "Games first", ADR 026 decisions 8 and 12): `PUT /rooms/{room}/setup`
 * `{mechanic, difficulty}` with a race's `rounds` or a board game's
 * `white` and `black` — either left out when the choice was of the other
 * kind, which the room then fills (`RoomGameSetupData::validate()`) — the
 * whole choice (`RoomGameSetupData::rules()`), from the host's phone or the big screen
 * (`RoomPolicy::start`: the host of an open room with no game running).
 * It answers 204 and nothing else: every device, the one that sent it
 * too, learns the choice from the state, after `room.changed`.
 */
final class RoomSetupController extends Controller
{
    public function __invoke(Request $request, Room $room, ChooseRoomGame $chooseRoomGame): Response
    {
        $chooseRoomGame->handle($room, RoomGameSetupData::validate($request->all(), $room));

        return response()->noContent();
    }
}
