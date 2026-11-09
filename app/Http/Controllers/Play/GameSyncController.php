<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\RecordBoardEvents;
use App\Domain\Play\Data\BoardEventsData;
use App\Domain\Play\Exceptions\BoardReplaced;
use App\Domain\Play\Models\GameSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /games/{game}/sync` (docs/architecture.md §4): the board's outbox.
 * Only a malformed body (422) or an old epoch (409 `board_replaced`) fails
 * the request; every other problem is the result of its own event.
 */
final class GameSyncController extends Controller
{
    public function __invoke(Request $request, GameSession $game, RecordBoardEvents $recordBoardEvents): JsonResponse
    {
        $data = BoardEventsData::validate($request->all());

        try {
            return new JsonResponse($recordBoardEvents->handle($game, $data)->toArray());
        } catch (BoardReplaced $replaced) {
            return new JsonResponse(['error' => 'board_replaced', 'game' => $replaced->game->toArray()], 409);
        }
    }
}
