<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Enums\WordProblem;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\Syllabifier;

/**
 * The rules every word of a pack follows (docs/architecture.md §3), in one
 * place: `ContentValidator` checks the pack files with them and
 * `CustomWordData` a teacher's words (ADR 007). The text is checked as
 * given: files must already be normalized, a teacher's input is normalized
 * before it gets here.
 *
 * - one word of alphabet letters, with no space, hyphen or sign;
 * - lowercase, or capitalised as a proper noun ("Bakı");
 * - a vowel, so it has syllables;
 * - an override (`syllables`) joins back to the word exactly, with one
 *   vowel in each part, and differs from what the Syllabifier gives.
 */
final class WordRules
{
    /**
     * What is wrong with the word, in the order a reader fixes it; empty
     * when it is a valid word. A word that is not one word of alphabet
     * letters gets only that problem.
     *
     * @param  list<string>|null  $syllables  the override, or null for none
     * @return list<WordProblem>
     */
    public static function problems(string $text, ?array $syllables = null): array
    {
        if (! self::isWord($text)) {
            return [WordProblem::Letters];
        }

        $problems = [];

        if (! self::isCased($text)) {
            $problems[] = WordProblem::Casing;
        }

        if ($syllables === null) {
            if (Syllabifier::vowelCount($text) === 0) {
                $problems[] = WordProblem::NoVowel;
            }
        } elseif (! Syllabifier::isSplitOf($text, $syllables)) {
            $problems[] = WordProblem::Split;
        } elseif ($syllables === Syllabifier::split($text)) {
            $problems[] = WordProblem::SplitByRule;
        }

        return $problems;
    }

    /**
     * One word of alphabet letters, with no space, hyphen or sign.
     */
    public static function isWord(string $text): bool
    {
        return preg_match('/^\p{L}+$/u', $text) === 1 && Alphabet::covers($text);
    }

    /**
     * Lowercase, or capitalised as a proper noun: only the first letter may
     * be a capital.
     */
    public static function isCased(string $text): bool
    {
        $lower = Az::lower($text);

        return $text === $lower || $text === Az::upper(mb_substr($text, 0, 1)).mb_substr($lower, 1);
    }

    /**
     * A reason from `words.errors.*` in the interface language, for a
     * teacher: a `WordProblem` value, or `built_in`, `no_level`, `taken`,
     * `too_many`, `not_in_pack`. It never contains the word.
     *
     * @param  array<string, string|int>  $replace
     */
    public static function message(string $reason, array $replace = []): string
    {
        $key = "words.errors.{$reason}";
        $message = __($key, $replace);

        return is_string($message) ? $message : $key;
    }

    /**
     * Whether the word fits at least one word level, by its syllable count.
     * True when no level has words: then no word level can refuse it.
     *
     * @param  iterable<LevelData>  $levels
     */
    public static function fitsALevel(WordData $word, iterable $levels): bool
    {
        $wordLevels = 0;

        foreach ($levels as $level) {
            if ($level->unit !== Unit::Word) {
                continue;
            }

            $wordLevels++;

            if ($level->fitsWord($word)) {
                return true;
            }
        }

        return $wordLevels === 0;
    }
}
