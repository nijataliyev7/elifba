<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\EndRoomMatch;
use App\Domain\Rooms\Actions\PlayRoomMove;
use App\Domain\Rooms\Actions\StartRoomMatch;
use App\Domain\Rooms\Actions\ThrowMatchTaunt;
use App\Domain\Rooms\Data\RoomMatchStateData;
use App\Domain\Rooms\Data\RoomMatchTauntData;
use App\Domain\Rooms\Data\RoomMoveData;
use App\Domain\Rooms\Data\StartRoomMatchData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A board room's match (docs/architecture.md §12 "Board games", ADR 034):
 * `GET /rooms/{room}/match` for the host and a present player (`watch`,
 * never cached), the host's start and end, a player's move and a player's
 * resignation, and a player's taunt at the other. Each write calls one action; the page then reads the match
 * again, as `room.changed` tells every other device to.
 */
final class RoomMatchController extends Controller
{
    /**
     * The big screen asks with `?for=screen` (`RoomPolicy::screen`): every
     * taunt shown now, with its target.
     */
    public function show(Request $request, Room $room, #[CurrentUser] User $account): JsonResponse
    {
        $forScreen = $request->query('for') === 'screen';

        if ($forScreen) {
            Gate::forUser($account)->authorize('screen', $room);
        }

        return new JsonResponse(RoomMatchStateData::for($room, $account, $forScreen), 200, ['Cache-Control' => 'no-store, private']);
    }

    public function taunt(Request $request, Room $room, #[CurrentUser] User $account, ThrowMatchTaunt $throwMatchTaunt): JsonResponse
    {
        $status = $throwMatchTaunt->handle($room, $account, RoomMatchTauntData::validate($request->all()));

        return new JsonResponse(['status' => $status->value], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function store(Request $request, Room $room, StartRoomMatch $startRoomMatch): Response
    {
        $startRoomMatch->handle($room, StartRoomMatchData::validate($request->all()));

        return response()->noContent();
    }

    public function destroy(Room $room, EndRoomMatch $endRoomMatch): Response
    {
        $endRoomMatch->byHost($room);

        return response()->noContent();
    }

    public function move(Request $request, Room $room, #[CurrentUser] User $account, PlayRoomMove $playRoomMove): JsonResponse
    {
        $status = $playRoomMove->handle($room, $account, RoomMoveData::validate($request->all()));

        return new JsonResponse(['status' => $status->value], 200, ['Cache-Control' => 'no-store, private']);
    }

    public function resign(Room $room, #[CurrentUser] User $account, EndRoomMatch $endRoomMatch): Response
    {
        $row = $room->rowOf($account) ?? throw new AuthorizationException;
        $endRoomMatch->resign($room, $row);

        return response()->noContent();
    }
}
