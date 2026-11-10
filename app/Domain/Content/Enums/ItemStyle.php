<?php

namespace App\Domain\Content\Enums;

/**
 * How a pack draws its items (docs/architecture.md §2). Each style is code
 * in `resources/js/game/styles/`; a pack only names one.
 */
enum ItemStyle: string
{
    case Balloon = 'balloon';
    case Wagon = 'wagon';
    case Card = 'card';
    case Bubble = 'bubble';

    /** An animal standing behind a plate with the item's text. */
    case Animal = 'animal';

    /** A fruit hanging behind a plate with the item's text. */
    case Fruit = 'fruit';

    /**
     * Whether each item stands in front of a picture from the pack's
     * `style.figures` (§2 "Figures"): the pack must list them, and no other
     * style may.
     */
    public function hasFigures(): bool
    {
        return match ($this) {
            self::Animal, self::Fruit => true,
            self::Balloon, self::Wagon, self::Card, self::Bubble => false,
        };
    }
}
