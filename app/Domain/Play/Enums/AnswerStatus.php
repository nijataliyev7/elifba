<?php

namespace App\Domain\Play\Enums;

/**
 * What became of one board event (docs/architecture.md §4, `AnswerResult`):
 *
 * - `correct` — a hit on the next right item, stored;
 * - `miss` — a hit on a wrong item, stored and counted as a miss;
 * - `skipped` — a skip that closed its round;
 * - `duplicate` — this `event_id` was handled before (or the round was
 *   already skipped, for a skip); nothing new is written;
 * - `stale` — nothing to apply: the round is closed, not in this game, the
 *   item is not in the round or already burst, or the game has ended.
 */
enum AnswerStatus: string
{
    case Correct = 'correct';
    case Miss = 'miss';
    case Skipped = 'skipped';
    case Duplicate = 'duplicate';
    case Stale = 'stale';
}
