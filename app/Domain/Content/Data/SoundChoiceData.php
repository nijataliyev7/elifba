<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\Effect;

/**
 * One sound of `content/sounds.json` (docs/architecture.md §3 "Sounds"): a
 * key the account's choice stores, a file under `content/assets`, a title
 * in both interface languages and, for a hit sound, the effects it suits.
 */
final readonly class SoundChoiceData
{
    /**
     * @param  list<Effect>  $effects  the effects it may play with; empty = every effect
     */
    public function __construct(
        public string $key,
        public string $file,
        public TitleData $title,
        public array $effects,
    ) {}

    /**
     * @param  array{key: string, file: string, title: array{az: string, en: string}, effects?: list<string>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            file: $data['file'],
            title: TitleData::fromArray($data['title']),
            effects: array_map(Effect::from(...), $data['effects'] ?? []),
        );
    }

    /**
     * Whether this sound may play in a pack with this effect: a pop never
     * plays where items are not burst (a state symbol is never popped, §8).
     */
    public function suits(Effect $effect): bool
    {
        return $this->effects === [] || in_array($effect, $this->effects, true);
    }
}
