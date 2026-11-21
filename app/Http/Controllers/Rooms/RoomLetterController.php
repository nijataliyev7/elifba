<?php

namespace App\Http\Controllers\Rooms;

use App\Domain\Rooms\Actions\BuyRoomLetter;
use App\Domain\Rooms\Data\RoomLetterData;
use App\Domain\Rooms\Models\Room;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /rooms/{room}/letters` `{event_id, round_id, position}` (zod
 * `RoomLetter` and `RoomLetterReceipt`, docs/architecture.md §12
 * "«Anaqram»: a clue, a shuffle and a bought letter (ADR 037)", ADR 037
 * decision 12): a present player buys the next letter of the round's word,
 * the buyer being the session's account (`RoomPolicy::play`). The receipt
 * is `{status}`, never kept by a cache; the letter comes with the buyer's
 * next state. 30 a minute per account (`throttle:room-letter`).
 */
final class RoomLetterController extends Controller
{
    public function __invoke(Request $request, Room $room, #[CurrentUser] User $account, BuyRoomLetter $buyRoomLetter): JsonResponse
    {
        $receipt = $buyRoomLetter->handle($room, $account, RoomLetterData::validate($request->all()));

        return new JsonResponse($receipt->toArray(), 200, ['Cache-Control' => 'no-store, private']);
    }
}
