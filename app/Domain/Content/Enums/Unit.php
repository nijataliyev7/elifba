<?php

namespace App\Domain\Content\Enums;

/**
 * What a level is made of (docs/architecture.md §2). The content key of an
 * item starts with it: `letter:ə`, `syllable:ba`, `word:alma`,
 * `sentence:{pack}:{id}`.
 */
enum Unit: string
{
    case Letter = 'letter';
    case Syllable = 'syllable';
    case Word = 'word';
    case Sentence = 'sentence';
}
