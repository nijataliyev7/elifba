<?php

namespace App\Domain\Play\Exceptions;

use App\Domain\Play\Enums\GameSessionStatus;
use RuntimeException;

/**
 * `session.status` could not reach Reverb (down, unreachable, refused).
 * Reported, never thrown at the request: the status change is already
 * committed and the board learns it on its next claim or sync. It names
 * the game by id and the cause by class only (docs/architecture.md §4, §7).
 */
final class SessionStatusNotBroadcast extends RuntimeException
{
    public function __construct(
        public readonly string $gameId,
        public readonly GameSessionStatus $status,
        string $cause,
    ) {
        parent::__construct("session.status ({$status->value}) for game {$gameId} was not broadcast: {$cause}.");
    }
}
