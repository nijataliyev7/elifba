<?php

namespace App\Domain\Play\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A round was solved (`RecordBoardEvents`, docs/architecture.md §1). Fired
 * after the commit; no listener in v1 — the hook for future statistics.
 * Ids only, never a name. A class's round carries the class id, a home
 * round the child id; the other is null.
 */
final readonly class RoundSolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string|null  $schoolClassId  the class of a class's game
     * @param  string|null  $childId  the child of a home game, always the one credited
     * @param  string|null  $studentId  credited student; null = whole class, or a home game
     */
    public function __construct(
        public string $roundId,
        public string $gameId,
        public ?string $schoolClassId,
        public ?string $childId,
        public ?string $studentId,
        public int $points,
    ) {}
}
