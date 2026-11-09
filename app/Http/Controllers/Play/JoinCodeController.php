<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\IssueJoinCode;
use App\Domain\Play\Data\PairingData;
use App\Domain\Play\Exceptions\GameOver;
use App\Domain\Play\Models\GameSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * `POST /games/{game}/join-code` (docs/architecture.md §4): the teacher
 * board's pairing card asks for the game's code — the valid one, or a new
 * one — with the QR's URL. A game that has ended gets 409 `game_over`.
 */
final class JoinCodeController extends Controller
{
    public function __invoke(GameSession $game, IssueJoinCode $issueJoinCode): JsonResponse
    {
        try {
            $issueJoinCode->handle($game);
        } catch (GameOver $over) {
            return new JsonResponse(['error' => 'game_over', 'game' => $over->game->toArray()], 409);
        }

        return new JsonResponse([
            'pairing' => (new PairingData((string) $game->join_code, $game->join_code_expires_at ?? now()))->toArray(),
        ]);
    }
}
