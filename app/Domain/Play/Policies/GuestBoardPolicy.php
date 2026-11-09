<?php

namespace App\Domain\Play\Policies;

use App\Domain\Play\Models\GuestBoard;
use App\Models\User;

/**
 * Guest boards, second layer (docs/architecture.md §4): `{board}` already
 * binds only the board itself for its bearer, or the active boards a
 * teacher claimed.
 */
final class GuestBoardPolicy
{
    /**
     * Poll for a game: the board itself.
     */
    public function view(User|GuestBoard $who, GuestBoard $board): bool
    {
        return $who instanceof GuestBoard && $who->is($board) && $board->isActive();
    }

    /**
     * Send the next game: the teacher who claimed it, while it is active.
     */
    public function show(User|GuestBoard $who, GuestBoard $board): bool
    {
        return $who instanceof User && $board->isClaimedBy($who) && $board->isActive();
    }

    /**
     * «Lövhəni bağla»: the board itself, or the teacher who claimed it.
     */
    public function close(User|GuestBoard $who, GuestBoard $board): bool
    {
        return match (true) {
            $who instanceof GuestBoard => $who->is($board),
            $who instanceof User => $board->isClaimedBy($who),
        };
    }
}
