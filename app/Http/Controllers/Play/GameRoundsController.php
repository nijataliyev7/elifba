<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\ChangeRoundCount;
use App\Domain\Play\Data\RoundCountData;
use App\Domain\Play\Exceptions\BoardReplaced;
use App\Domain\Play\Exceptions\RoundsLocked;
use App\Domain\Play\Models\GameSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /games/{game}/rounds` (docs/architecture.md §4 "Round count"): the
 * board's choice of how many rounds, before anything is answered. Answers
 * the new `GameState`; an old epoch is 409 `board_replaced`, a game that has
 * begun or ended 409 `rounds_locked`, and a count out of range or a setup
 * that can no longer start 422.
 */
final class GameRoundsController extends Controller
{
    public function __invoke(Request $request, GameSession $game, ChangeRoundCount $changeRoundCount): JsonResponse
    {
        $data = RoundCountData::validate($request->all());

        try {
            return new JsonResponse($changeRoundCount->handle($game, $data)->toArray());
        } catch (BoardReplaced $replaced) {
            return new JsonResponse(['error' => 'board_replaced', 'game' => $replaced->game->toArray()], 409);
        } catch (RoundsLocked $locked) {
            return new JsonResponse(['error' => 'rounds_locked', 'game' => $locked->game->toArray()], 409);
        }
    }
}
