<?php

namespace App\Domain\Play\Policies;

use App\Domain\Play\Models\GameSession;
use App\Domain\Play\Models\GuestBoard;
use App\Models\User;

/**
 * Teacher isolation, second layer for games (docs/architecture.md §4, §7):
 * `{game}` already binds only the teacher's own games — or, for a guest
 * board's bearer, exactly the game the board shows — and every route with
 * it also passes this policy (`->can()`).
 *
 * `view` and `update` are the teacher's alone (the board page, the join
 * code, the remote). `play` is what a board does — claim, read the state,
 * sync, change the status — and is the only ability a guest board has.
 */
final class GameSessionPolicy
{
    /**
     * Open the board page or the remote.
     */
    public function view(User|GuestBoard $who, GameSession $game): bool
    {
        return $who instanceof User && $game->isOwnedBy($who);
    }

    /**
     * Pair a phone (join code), drive the game from the remote.
     */
    public function update(User|GuestBoard $who, GameSession $game): bool
    {
        return $who instanceof User && $game->isOwnedBy($who);
    }

    /**
     * Claim the board, read the state, sync results, pause / resume / end:
     * the owner, or the active guest board that shows this game and was
     * claimed by its owner.
     */
    public function play(User|GuestBoard $who, GameSession $game): bool
    {
        return match (true) {
            $who instanceof User => $game->isOwnedBy($who),
            $who instanceof GuestBoard => $who->showsGame($game->id) && $who->user_id === $game->user_id,
        };
    }
}
