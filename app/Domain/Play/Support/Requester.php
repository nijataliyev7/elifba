<?php

namespace App\Domain\Play\Support;

use App\Domain\Play\Models\GuestBoard;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Who the current request is authenticated as, through whichever guard the
 * `auth:` middleware chose (docs/architecture.md §4): a teacher (`web`) or
 * a guest board (`board`, on the board routes). This is what `Auth::user()`
 * returns at run time; static analysis types `Auth::user()` by the default
 * guard alone, so the bindings read the resolver and narrow it here.
 */
final class Requester
{
    public static function current(): User|GuestBoard|null
    {
        $who = Auth::userResolver()();

        return $who instanceof User || $who instanceof GuestBoard ? $who : null;
    }
}
