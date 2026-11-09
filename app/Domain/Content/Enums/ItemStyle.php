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
}
