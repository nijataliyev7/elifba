<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Data\RoundItemData;

/**
 * Rounds of `pop` («Oxu və vur», for beginners; docs/architecture.md §2,
 * "The pop builder"): a `find` round whose solution is every item. The
 * child reads any item aloud and hits it; it bursts, the rest stay, and the
 * round is solved when the last one goes. There is no wrong item, so a
 * solved round never has a miss.
 *
 * `AnyRoundBuilder` picks the items exactly as for `find` (same pool, deck,
 * decoys and shortages), so the same seed shows the same items. The prompt
 * names no target: its template takes no text and has no picture.
 */
final readonly class PopRoundBuilder extends AnyRoundBuilder
{
    protected function mechanic(): Mechanic
    {
        return Mechanic::Pop;
    }

    protected function solution(array $items, RoundItemData $target): array
    {
        return array_map(static fn (RoundItemData $item): string => $item->id, $items);
    }

    protected function prompt(PromptTemplateData $template, RoundItemData $target): PromptData
    {
        return new PromptData(text: $template->text, show: null, image: null, audio: null, tail: null);
    }
}
