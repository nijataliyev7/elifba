<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\TransitionGameSession;
use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Data\StatusChangeData;
use App\Domain\Play\Exceptions\InvalidTransition;
use App\Domain\Play\Models\GameSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `PATCH /games/{game}/status` (docs/architecture.md §4): pause, resume or
 * end a game from the board or the phone. A change the lifecycle does not
 * allow (resuming a finished game) is 409 `invalid_transition`; asking for
 * the current status again changes nothing.
 */
final class GameStatusController extends Controller
{
    public function __invoke(Request $request, GameSession $game, TransitionGameSession $transition): JsonResponse
    {
        $data = StatusChangeData::validate($request->all());

        try {
            $transition->handle($game, $data->status);
        } catch (InvalidTransition $invalid) {
            return new JsonResponse(['error' => 'invalid_transition', 'game' => $invalid->game->toArray()], 409);
        }

        return new JsonResponse(['game' => GameStatusData::of($game)->toArray()]);
    }
}
