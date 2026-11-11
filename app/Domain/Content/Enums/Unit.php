<?php

namespace App\Domain\Content\Enums;

/**
 * What an item of a level is (docs/architecture.md §2, ADR 012). The content
 * key of an item starts with it, except a picture's, which keeps its word's
 * key: `letter:ə`, `syllable:ba`, `word:alma`, `sentence:{pack}:{id}`,
 * `number:7`.
 */
enum Unit: string
{
    case Letter = 'letter';
    case Syllable = 'syllable';
    case Word = 'word';
    case Sentence = 'sentence';

    /** A word of the pack shown as its picture, for a child who cannot read yet (R5). */
    case Picture = 'picture';

    /** A number of the level's range shown as its digits (R5). */
    case Number = 'number';

    /**
     * Whether a child reads the item. Learned letters, the letter case,
     * chosen letters and «Yalnız mənim sözlərim» apply only where it is
     * true; a picture or number level ignores them all.
     */
    public function reads(): bool
    {
        return match ($this) {
            self::Letter, self::Syllable, self::Word, self::Sentence => true,
            self::Picture, self::Number => false,
        };
    }

    /**
     * The four units a child reads, which a pack lists together or not at all.
     *
     * @return list<self>
     */
    public static function reading(): array
    {
        return array_values(array_filter(self::cases(), static fn (self $unit): bool => $unit->reads()));
    }
}
