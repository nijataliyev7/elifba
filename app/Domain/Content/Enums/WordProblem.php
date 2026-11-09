<?php

namespace App\Domain\Content\Enums;

/**
 * Why a word cannot be a word of a pack (docs/architecture.md §3): the
 * answers of `Content\Support\WordRules`, which `ContentValidator` reports
 * for pack files and `CustomWordData` for a teacher's words. The value is
 * the reason's key in `words.errors.*`.
 */
enum WordProblem: string
{
    /** Not one word of alphabet letters: a space, hyphen, sign, digit or foreign letter. */
    case Letters = 'letters';

    /** Neither lowercase nor capitalised as a proper noun. */
    case Casing = 'casing';

    /** No vowel, so no syllable. */
    case NoVowel = 'no_vowel';

    /** The override does not join back to the word exactly, with one vowel in each part. */
    case Split = 'split';

    /** The override is what the Syllabifier gives already. */
    case SplitByRule = 'split_by_rule';
}
