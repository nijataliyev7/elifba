<?php

namespace App\Domain\Play\Exceptions;

use App\Domain\Play\Data\GameStatusData;
use RuntimeException;

/**
 * The board asked for another number of rounds after the game began — a
 * round answered, skipped or missed, an event stored — or after it ended
 * (docs/architecture.md §4, `ChangeRoundCount`). `PATCH /games/{game}/rounds`
 * answers 409 `rounds_locked` with the game's status.
 */
final class RoundsLocked extends RuntimeException
{
    public function __construct(public readonly GameStatusData $game)
    {
        parent::__construct('The game has begun or ended: its rounds stay.');
    }
}
