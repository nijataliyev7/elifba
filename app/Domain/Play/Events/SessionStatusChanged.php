<?php

namespace App\Domain\Play\Events;

use App\Domain\Play\Enums\GameSessionStatus;
use App\Domain\Play\Support\GameChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * `session.status` on the game's channel (docs/architecture.md §4): a game
 * was paused, resumed, finished or abandoned through HTTP or the scheduler,
 * so a board or remote still connected learns it. Sent at once (no queue)
 * by `BroadcastSessionStatus` after the change is committed. The payload is
 * `{v: 1, status}`; the channel names the game. No names, ever.
 */
final readonly class SessionStatusChanged implements ShouldBroadcastNow
{
    public function __construct(
        public string $gameId,
        public GameSessionStatus $status,
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel(GameChannel::name($this->gameId));
    }

    public function broadcastAs(): string
    {
        return 'session.status';
    }

    /**
     * @return array{v: int, status: string}
     */
    public function broadcastWith(): array
    {
        return ['v' => 1, 'status' => $this->status->value];
    }
}
