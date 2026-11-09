<?php

namespace App\Domain\Play\Listeners;

use App\Domain\Play\Events\GameSessionTransitioned;
use App\Domain\Play\Events\SessionStatusChanged;
use App\Domain\Play\Exceptions\SessionStatusNotBroadcast;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * Broadcasts `session.status` when a game changes status
 * (docs/architecture.md §1, §4). `GameSessionTransitioned` is dispatched
 * after the commit, so this never runs inside the writing transaction. A
 * broadcast that fails (Reverb down) is reported with ids only and does not
 * fail the status change: the board learns it on its next claim or sync.
 */
final readonly class BroadcastSessionStatus
{
    public function __construct(private Dispatcher $events) {}

    public function handle(GameSessionTransitioned $event): void
    {
        try {
            $this->events->dispatch(new SessionStatusChanged($event->gameId, $event->to));
        } catch (Throwable $failure) {
            report(new SessionStatusNotBroadcast($event->gameId, $event->to, $failure::class));
        }
    }
}
