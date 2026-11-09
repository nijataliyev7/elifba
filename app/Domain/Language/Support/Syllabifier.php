<?php

namespace App\Domain\Language\Support;

/**
 * Splits an Azerbaijani word into syllables (heca) by the textbook rule
 * (docs/architecture.md §3): one vowel per syllable; between two vowels,
 * no consonant splits between them (`sa-at`), one consonant goes to the next
 * syllable (`a-ta`), two or more split before the last one (`al-ma`,
 * `dost-luq`). Consonants before the first vowel join the first syllable
 * (`qra-fin`), those after the last vowel join the last (`dost`).
 *
 * Vowels come from `content/alphabet.json` and are tested through
 * `Az::lower`, so `I` and `İ` count; every other character counts as a
 * consonant. The parts keep the word's own casing. Loanwords the rule gets
 * wrong are fixed by a `syllables` override in their pack, never here.
 */
final class Syllabifier
{
    /**
     * @return list<string> the syllables, or the word itself when it has fewer than two vowels
     */
    public static function split(string $word): array
    {
        if ($word === '') {
            return [];
        }

        $characters = mb_str_split($word);
        $vowels = array_keys(array_filter($characters, self::isVowel(...)));

        if (count($vowels) < 2) {
            return [$word];
        }

        $parts = [];
        $start = 0;

        for ($k = 1, $count = count($vowels); $k < $count; $k++) {
            $break = $vowels[$k] - $vowels[$k - 1] === 1 ? $vowels[$k] : $vowels[$k] - 1;
            $parts[] = implode('', array_slice($characters, $start, $break - $start));
            $start = $break;
        }

        $parts[] = implode('', array_slice($characters, $start));

        return $parts;
    }

    /**
     * The vowel/consonant pattern of a syllable: "a" → "V", "ba" → "CV",
     * "al" → "VC", "bal" → "CVC", "üst" → "VCC", "dost" → "CVCC". Levels
     * pick syllables by these shapes (`content/levels.json`).
     */
    public static function shape(string $syllable): string
    {
        return implode('', array_map(
            static fn (string $character): string => self::isVowel($character) ? 'V' : 'C',
            mb_str_split($syllable),
        ));
    }

    public static function vowelCount(string $text): int
    {
        return count(array_filter(mb_str_split($text), self::isVowel(...)));
    }

    /**
     * Whether the parts are a valid split of the word: they join back to it
     * exactly (same letters, same case) and each has exactly one vowel. This
     * is what a pack's `syllables` override must satisfy.
     *
     * @param  list<string>  $parts
     */
    public static function isSplitOf(string $word, array $parts): bool
    {
        if ($parts === [] || implode('', $parts) !== $word) {
            return false;
        }

        foreach ($parts as $part) {
            if (self::vowelCount($part) !== 1) {
                return false;
            }
        }

        return true;
    }

    private static function isVowel(string $character): bool
    {
        return Alphabet::isVowel(Az::lower($character));
    }
}
