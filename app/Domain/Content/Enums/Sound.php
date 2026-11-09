<?php

namespace App\Domain\Content\Enums;

/**
 * The moments a pack may give a sound to (`style.sounds` in a pack).
 */
enum Sound: string
{
    case Hit = 'hit';
    case Miss = 'miss';
    case Solved = 'solved';
}
