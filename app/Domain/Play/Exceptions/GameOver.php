<?php

namespace App\Domain\Play\Exceptions;

use App\Domain\Play\Data\GameStatusData;
use RuntimeException;

/**
 * A finished or abandoned game was asked for something only a live or
 * paused one has, such as a join code (docs/architecture.md §4).
 */
final class GameOver extends RuntimeException
{
    public function __construct(public readonly GameStatusData $game)
    {
        parent::__construct("The game is {$game->status->value}.");
    }
}
