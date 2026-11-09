<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\CloseGuestBoard;
use App\Domain\Play\Actions\OpenGuestBoard;
use App\Domain\Play\Data\GuestBoardData;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\BoardProps;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The guest board's own endpoints (docs/architecture.md §4).
 *
 * - `POST /guest-boards` (public, 6 a minute per IP): `/lovhe` opens a board
 *   and gets its bearer token — the only time the secret leaves the server.
 * - `GET /guest-boards/{board}` (its bearer): the board polls every 2 s
 *   until a game arrives; then the answer carries the board props
 *   (`BoardProps`), because a bearer cannot make an Inertia visit.
 * - `DELETE /guest-boards/{board}` (its bearer, or the teacher who claimed
 *   it): «Lövhəni bağla».
 */
final class GuestBoardController extends Controller
{
    public function store(OpenGuestBoard $openGuestBoard): JsonResponse
    {
        $opened = $openGuestBoard->handle();

        return new JsonResponse([
            'board' => (new GuestBoardData($opened->board))->toArray(),
            'token' => $opened->token,
        ], 201);
    }

    public function show(GuestBoard $board): JsonResponse
    {
        $game = $board->game;

        return new JsonResponse([
            'board' => (new GuestBoardData($board))->toArray(),
            'props' => $game === null ? null : BoardProps::of($game),
        ]);
    }

    public function destroy(Request $request, GuestBoard $board, CloseGuestBoard $closeGuestBoard): Response|RedirectResponse
    {
        $closeGuestBoard->handle($board);

        return $request->expectsJson() ? response()->noContent() : back();
    }
}
