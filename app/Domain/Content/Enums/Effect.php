<?php

namespace App\Domain\Content\Enums;

/**
 * What happens to an item hit correctly (docs/architecture.md §2). A state
 * symbol is never burst (§8), so the symbols pack uses `sparkle`.
 */
enum Effect: string
{
    case Burst = 'burst';
    case Sparkle = 'sparkle';
}
