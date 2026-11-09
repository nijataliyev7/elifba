<?php

namespace App\Domain\Content\Enums;

/**
 * How a pack draws the slots of build rounds (docs/architecture.md §2).
 */
enum SlotStyle: string
{
    case Wagon = 'wagon';
    case Frame = 'frame';
}
