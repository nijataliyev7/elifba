<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\RecordRoomAct;
use App\Domain\Rooms\Data\RoomActData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /rooms/{room}/acts` (docs/architecture.md §12 "Board games", "An
 * act", ADR 034 decision 9): a seat's own move, draw offer, answer to one
 * or resignation in a board game. The body never names a player; the
 * receipt is `{status, revision}`, never cached.
 */
final class RoomActController extends Controller
{
    public function __invoke(Request $request, Room $room, #[CurrentUser] User $account, RecordRoomAct $recordRoomAct): JsonResponse
    {
        $receipt = $recordRoomAct->handle($room, $account, RoomActData::validate($request->all()));

        return new JsonResponse($receipt->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
