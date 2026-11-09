<?php

use App\Domain\Play\Models\GuestBoard;
use App\Domain\Play\Support\GameChannel;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * One private channel per game, `private-game.{id}` (docs/architecture.md
 * §4): the owner's session, or the bearer of the guest board that shows
 * the game, while it is live or paused. The bearer is tried first, as on
 * the board routes. Reverb takes client events only from members
 * (`accept_client_events_from = members`). Payloads carry ids only.
 */
Broadcast::channel(
    'game.{game}',
    static fn (User|GuestBoard $who, string $game): bool => GameChannel::allows($who, $game),
    ['guards' => ['board', 'web']],
);
