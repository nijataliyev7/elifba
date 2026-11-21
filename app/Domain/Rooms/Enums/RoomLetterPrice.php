<?php

namespace App\Domain\Rooms\Enums;

/**
 * What the next bought letter costs an anagram's answer, as a word
 * (docs/architecture.md §12 "«Anaqram»: a clue, a shuffle and a bought
 * letter (ADR 037)", ADR 037 decisions 14 and 17), named by
 * `RoomPointsRule::letterPrice()` — the page says the word and never
 * computes it:
 *
 * - `half`: nothing bought yet; after it the answer keeps half its points;
 * - `rest`: one bought; after the next the answer keeps nothing;
 * - `none`: two or more bought; nothing is left to lose.
 */
enum RoomLetterPrice: string
{
    case Half = 'half';
    case Rest = 'rest';
    case None = 'none';
}
