<?php

namespace App\Http\Controllers\Play;

use App\Domain\Play\Actions\ReplayGame;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Models\GameSession;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * `POST /games/{game}/replay` (docs/architecture.md §2 "Game setup"):
 * «Yenidən oyna» on a teacher or home board's end screen starts the same
 * game again (`ReplayGame`) and opens its board. A game still open goes back
 * to its board; one that can no longer start goes to the setup page of the
 * same class or child with the reason as a toast. An archived class is
 * hidden from games, as on the setup page.
 */
final class GameReplayController extends Controller
{
    public function __invoke(GameSession $game, ReplayGame $replayGame): RedirectResponse
    {
        $players = GamePlayers::of($game);

        abort_if($players->class?->archived_at !== null, 404);

        if (! $game->status->isFinal()) {
            return to_route('games.board', $game);
        }

        try {
            return to_route('games.board', $replayGame->handle($game));
        } catch (ValidationException $refused) {
            $reason = Arr::first(Arr::flatten($refused->errors()));

            Inertia::flash('toast', ['type' => 'warning', 'message' => __('games.board.replay_refused', ['reason' => is_string($reason) ? $reason : ''])]);

            return $players->child !== null
                ? to_route('children.games.create', $players->child)
                : to_route('classes.games.create', $players->class);
        }
    }
}
