<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Data\RoundItemData;

/**
 * Rounds of `find` («Tap!», "Şarı partlat" with the balloons pack;
 * docs/architecture.md §2): a prompt names a target, `items` of the level
 * float on the board, one of them is the target. `AnyRoundBuilder` picks
 * the items; the target is the item the deck dealt.
 *
 * The prompt shows the target as text and carries no picture: a picture
 * would let a child match pictures instead of reading.
 */
final readonly class FindRoundBuilder extends AnyRoundBuilder
{
    protected function mechanic(): Mechanic
    {
        return Mechanic::Find;
    }

    protected function solution(array $items, RoundItemData $target): array
    {
        return [$target->id];
    }

    protected function prompt(PromptTemplateData $template, RoundItemData $target, ?string $audio): PromptData
    {
        return new PromptData(text: $template->fill($target->text), show: $target->text, image: null, count: null, audio: $audio, tail: null);
    }
}
