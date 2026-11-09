<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\BoardProps;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;

/**
 * `GET /games/{game}/remote`, the teacher's phone (docs/architecture.md §4):
 * the board's props (students with avatars for the grid) and, when a guest
 * board of this teacher shows the game, that board's id — to send it the
 * next game or close it. The phone writes nothing that counts; it talks to
 * the board through client events (next session).
 */
final class RemoteController extends Controller
{
    public function __invoke(GameSession $game, #[CurrentUser] User $teacher): Response
    {
        $board = GuestBoard::query()
            ->active()
            ->where('user_id', $teacher->id)
            ->where('game_session_id', $game->id)
            ->latest('claimed_at')
            ->first(['id']);

        return Inertia::render('games/remote', [
            ...BoardProps::of($game),
            'guestBoard' => $board === null ? null : ['id' => $board->id],
        ]);
    }
}
