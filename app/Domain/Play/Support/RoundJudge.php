<?php

namespace App\Domain\Play\Support;

use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\SolutionData;
use App\Domain\Play\Data\VerdictData;
use App\Domain\Play\Enums\AnswerStatus;
use App\Domain\Play\Enums\SolutionOrder;

/**
 * The judge (docs/architecture.md §2): is this item the next correct one?
 * Authoritative — `RecordBoardEvents` stores what it says. The board's
 * `judge()` in `resources/js/game/engine.ts` mirrors it for instant
 * feedback; both run every case of `tests/Fixtures/contract/judge.json`.
 *
 * - `any` (find): an item of the solution not hit yet is correct and
 *   bursts; the round is solved when every solution item is hit. A hit on
 *   an item that already burst is `stale`.
 * - `sequence` (build): the item at `solution.item_ids[progress.length]` is
 *   correct; tiles are reusable, so the same item may be correct again.
 * - Anything else in the round is a miss. An item that is not in the round,
 *   or any hit on a solved round, is `stale`.
 */
final class RoundJudge
{
    /**
     * @param  list<string>  $progress  item ids hit correctly so far, in order
     */
    public static function judge(RoundData $round, array $progress, string $itemId): VerdictData
    {
        $solution = $round->solution;

        if (self::isSolved($solution, $progress) || $round->item($itemId) === null) {
            return self::stale($solution, $progress);
        }

        if ($solution->order === SolutionOrder::Any) {
            if (in_array($itemId, $progress, true)) {
                return self::stale($solution, $progress);
            }

            $correct = in_array($itemId, $solution->item_ids, true);
        } else {
            $correct = ($solution->item_ids[count($progress)] ?? null) === $itemId;
        }

        if (! $correct) {
            return new VerdictData(AnswerStatus::Miss, $progress, false);
        }

        $progress = [...$progress, $itemId];

        return new VerdictData(AnswerStatus::Correct, $progress, self::isSolved($solution, $progress));
    }

    /**
     * @param  list<string>  $progress
     */
    public static function isSolved(SolutionData $solution, array $progress): bool
    {
        return $solution->order === SolutionOrder::Any
            ? array_diff($solution->item_ids, $progress) === []
            : count($progress) >= count($solution->item_ids);
    }

    /**
     * @param  list<string>  $progress
     */
    private static function stale(SolutionData $solution, array $progress): VerdictData
    {
        return new VerdictData(AnswerStatus::Stale, $progress, self::isSolved($solution, $progress));
    }
}
