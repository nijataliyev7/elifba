<?php

namespace App\Domain\Play\Exceptions;

use App\Domain\Play\Data\GameStatusData;
use App\Domain\Play\Enums\GameSessionStatus;
use RuntimeException;

/**
 * A status change `GameSessionStatus::allowedTransitions()` does not allow,
 * such as resuming a finished game.
 */
final class InvalidTransition extends RuntimeException
{
    public function __construct(
        public readonly GameStatusData $game,
        public readonly GameSessionStatus $to,
    ) {
        parent::__construct("A {$game->status->value} game cannot become {$to->value}.");
    }
}
