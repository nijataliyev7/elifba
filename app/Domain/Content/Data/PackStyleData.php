<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\Effect;
use App\Domain\Content\Enums\ItemStyle;
use App\Domain\Content\Enums\SlotStyle;
use App\Domain\Content\Enums\Sound;

/**
 * A pack's look, chosen from the closed lists of styles that exist as code
 * (docs/architecture.md §2). Paths are relative to `content/assets`.
 */
final readonly class PackStyleData
{
    /**
     * @param  list<string>  $palette  `#RRGGBB` colours
     * @param  array<value-of<Sound>, string>  $sounds  sound key → path
     */
    public function __construct(
        public ItemStyle $item,
        public SlotStyle $slot,
        public Effect $effect,
        public ?string $backdrop,
        public array $palette,
        public array $sounds,
    ) {}

    /**
     * @param  array{item: string, slot: string, effect: string, backdrop?: string, palette: list<string>, sounds?: array<value-of<Sound>, string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            item: ItemStyle::from($data['item']),
            slot: SlotStyle::from($data['slot']),
            effect: Effect::from($data['effect']),
            backdrop: $data['backdrop'] ?? null,
            palette: $data['palette'],
            sounds: $data['sounds'] ?? [],
        );
    }

    public function sound(Sound $sound): ?string
    {
        return $this->sounds[$sound->value] ?? null;
    }
}
