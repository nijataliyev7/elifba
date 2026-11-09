<?php

namespace App\Domain\Play\Enums;

/**
 * Where a hit came from (docs/architecture.md §4): the teacher's phone or a
 * touch or click on the board itself. The board writes both.
 */
enum HitSource: string
{
    case Remote = 'remote';
    case Board = 'board';
}
