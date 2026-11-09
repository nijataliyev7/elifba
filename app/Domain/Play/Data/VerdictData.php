<?php

namespace App\Domain\Play\Data;

use App\Domain\Play\Enums\AnswerStatus;

/**
 * What `RoundJudge` says about one hit: `correct`, `miss` or `stale`, the
 * round's progress after it, and whether the round is now solved.
 */
final readonly class VerdictData
{
    /**
     * @param  list<string>  $progress  item ids hit correctly so far, in order, including this hit when it is correct
     */
    public function __construct(
        public AnswerStatus $status,
        public array $progress,
        public bool $solved,
    ) {}
}
