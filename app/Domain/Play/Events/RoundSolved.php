<?php

namespace App\Domain\Play\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A round was solved (`RecordBoardEvents`, docs/architecture.md §1). Fired
 * after the commit; no listener in v1 — the hook for future statistics.
 * Ids only, never a name.
 */
final readonly class RoundSolved implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  string|null  $studentId  credited student; null = whole class
     */
    public function __construct(
        public string $roundId,
        public string $gameId,
        public string $schoolClassId,
        public ?string $studentId,
        public int $points,
    ) {}
}
