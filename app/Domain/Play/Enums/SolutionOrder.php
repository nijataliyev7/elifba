<?php

namespace App\Domain\Play\Enums;

/**
 * How a round's solution is hit (docs/architecture.md §2): `any` — each
 * solution item once, in any order (`find`: one item, which bursts);
 * `sequence` — the items in order, a tile may repeat (`nə-nə` = a, a).
 */
enum SolutionOrder: string
{
    case Any = 'any';
    case Sequence = 'sequence';
}
