<?php

namespace App\Domain\Play\Support;

use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

/**
 * The private channel of a game, `private-game.{id}` (docs/architecture.md
 * §4), and who may join it: while the game is live or paused, its owner's
 * session or the active guest board that shows it (`GameSessionPolicy::play`).
 * Finished and abandoned games have no members. Payloads on it carry ids
 * only.
 *
 * The callback in routes/channels.php takes the id as a string and loads
 * the game here: the `{game}` route binding follows the web guard, while
 * `/broadcasting/auth` tries the `board` guard first.
 */
final class GameChannel
{
    public static function name(string $gameId): string
    {
        return 'game.'.$gameId;
    }

    public static function allows(User|GuestBoard $who, string $gameId): bool
    {
        $game = GameSession::query()->find($gameId);

        return $game !== null
            && $game->status->isOpen()
            && Gate::forUser($who)->allows('play', $game);
    }
}
