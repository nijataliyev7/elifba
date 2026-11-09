<?php

namespace App\Domain\Play\Exceptions;

use App\Domain\Play\Data\GameStatusData;
use RuntimeException;

/**
 * A board synced with an old `board_epoch`: another board claimed the game
 * since (docs/architecture.md §4). `/sync` answers 409 `board_replaced` and
 * that board stops.
 */
final class BoardReplaced extends RuntimeException
{
    public function __construct(public readonly GameStatusData $game)
    {
        parent::__construct('Another board has claimed this game.');
    }
}
