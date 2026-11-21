<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\SetRoomPlayerReady;
use App\Domain\Rooms\Data\RoomReadyData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * A player's «Hazıram» between games, or taking it back
 * (docs/architecture.md §12 "Ready for the next game", ADR 035 decision 4):
 * `PUT /rooms/{room}/ready` `{on}`, for a present player of an open room
 * who is not the host while no game runs (`RoomPolicy::ready`), 30 a
 * minute per account (`throttle:room-ready`). It answers 204 and nothing
 * else: every device, the one that tapped too, learns the mark from the
 * state, after `room.changed`.
 */
final class RoomReadyController extends Controller
{
    public function __invoke(Request $request, Room $room, #[CurrentUser] User $account, SetRoomPlayerReady $setRoomPlayerReady): Response
    {
        $setRoomPlayerReady->handle($room, $account, RoomReadyData::validate($request->all()));

        return response()->noContent();
    }
}
