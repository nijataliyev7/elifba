<?php

namespace App\Domain\Play\Enums;

use App\Domain\Language\Support\Az;

/**
 * How a game writes its content (`game_sessions.letter_case`,
 * docs/architecture.md §2). `upper` is every letter capital, with
 * Azerbaijani casing (i → İ, ı → I); `lower` is the content as written:
 * letters and syllables lowercase, words and sentences as the pack spells
 * them (a proper noun keeps its capital).
 */
enum LetterCase: string
{
    case Upper = 'upper';
    case Lower = 'lower';

    /**
     * The text as the board shows it.
     *
     * @param  string  $written  the content as written (letters and syllables lowercase)
     */
    public function apply(string $written): string
    {
        return $this === self::Upper ? Az::upper($written) : $written;
    }
}
