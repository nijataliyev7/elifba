<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\BoardState;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `GET /games/{game}/state` (zod `GameState`): every round and where each
 * stands, so a reloaded board resumes at the first open round.
 */
final class GameStateController extends Controller
{
    public function __invoke(GameSession $game): JsonResponse
    {
        return new JsonResponse(BoardState::of($game)->toArray());
    }
}
