<?php

namespace App\Domain\Content\Enums;

/**
 * How much of a word's clue an `anagram` level shows (docs/architecture.md
 * §12 "Room games", `content/rooms/levels.json`'s `clue`; ADR 037 as
 * amended 2026-11-22): a clue of `content/rooms/clues.json` is its word's
 * field, then a trait after the first comma — «Metal birləşdirmə, qığılcım
 * saçır». The easiest level shows it whole; a harder one its field alone,
 * «Metal birləşdirmə», so the letters do the rest.
 */
enum RoomClueForm: string
{
    case Full = 'full';
    case Field = 'field';
}
