<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\ClaimBoard;
use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Support\BoardProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The teacher board's page on the projector (docs/architecture.md §4) and
 * the claim every board makes when it loads. The page props come from
 * `BoardProps`, the same builder the guest board's JSON uses. The claim is
 * open to a guest board's bearer for the game it shows.
 */
final class BoardController extends Controller
{
    public function show(GameSession $game): Response
    {
        return Inertia::render('games/board', BoardProps::of($game));
    }

    public function claim(GameSession $game, ClaimBoard $claimBoard): JsonResponse
    {
        return new JsonResponse(['game' => $claimBoard->handle($game)->toArray()]);
    }
}
