<?php

namespace App\Domain\Play\Events;

use App\Domain\Play\Enums\GameSessionStatus;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A game changed status (`TransitionGameSession`, docs/architecture.md §1).
 * Dispatched after the transaction commits, so a listener never runs inside
 * it. `Listeners\BroadcastSessionStatus` broadcasts `session.status` on the
 * game's channel. Ids only, never a name.
 */
final readonly class GameSessionTransitioned implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public string $gameId,
        public GameSessionStatus $from,
        public GameSessionStatus $to,
    ) {}
}
