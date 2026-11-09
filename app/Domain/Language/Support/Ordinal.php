<?php

namespace App\Domain\Language\Support;

use InvalidArgumentException;

/**
 * Ordinal numbers with vowel harmony (docs/architecture.md §3): 1-ci, 3-cü,
 * 6-cı, 9-cu, 10-cu, 100-cü, 1000-ci. The suffix follows the last vowel of
 * the last number word said aloud: 21 is "iyirmi bir" → bir → 21-ci, 40 is
 * "qırx" → 40-cı, 110 is "yüz on" → on → 110-cu, 2500 is "iki min beş yüz"
 * → yüz → 2500-cü.
 *
 * The server builds every place label (`place_label`); TypeScript never
 * concatenates a suffix.
 */
final class Ordinal
{
    private const array UNITS = [1 => 'bir', 2 => 'iki', 3 => 'üç', 4 => 'dörd', 5 => 'beş', 6 => 'altı', 7 => 'yeddi', 8 => 'səkkiz', 9 => 'doqquz'];

    private const array TENS = [1 => 'on', 2 => 'iyirmi', 3 => 'otuz', 4 => 'qırx', 5 => 'əlli', 6 => 'altmış', 7 => 'yetmiş', 8 => 'səksən', 9 => 'doxsan'];

    /**
     * The suffix after the last vowel of that word (four-way harmony).
     */
    private const array HARMONY = ['a' => 'cı', 'ı' => 'cı', 'e' => 'ci', 'ə' => 'ci', 'i' => 'ci', 'o' => 'cu', 'u' => 'cu', 'ö' => 'cü', 'ü' => 'cü'];

    /**
     * "1-ci", "3-cü", "6-cı", "10-cu".
     *
     * @throws InvalidArgumentException below 1: places start at 1
     */
    public static function make(int $number): string
    {
        return $number.'-'.self::suffix($number);
    }

    /**
     * A place label in an interface language: the Azerbaijani ordinal
     * ("1-ci", "3-cü") in `az`, the English one ("1st", "2nd", "3rd",
     * "11th", "21st") in `en`.
     *
     * @throws InvalidArgumentException below 1: places start at 1
     */
    public static function label(int $number, string $locale): string
    {
        if ($locale !== 'en') {
            return self::make($number);
        }

        if ($number < 1) {
            throw new InvalidArgumentException("Ordinals start at 1, got {$number}.");
        }

        $suffix = match (true) {
            in_array($number % 100, [11, 12, 13], true) => 'th',
            $number % 10 === 1 => 'st',
            $number % 10 === 2 => 'nd',
            $number % 10 === 3 => 'rd',
            default => 'th',
        };

        return $number.$suffix;
    }

    /**
     * "ci", "cı", "cu" or "cü".
     *
     * @throws InvalidArgumentException below 1: places start at 1
     */
    public static function suffix(int $number): string
    {
        $word = self::lastWord($number);
        $suffix = null;

        foreach (mb_str_split($word) as $letter) {
            $suffix = self::HARMONY[$letter] ?? $suffix;
        }

        return $suffix ?? throw new InvalidArgumentException("\"{$word}\" has no vowel.");
    }

    /**
     * The word said last: the one for the lowest non-zero part of the number.
     */
    private static function lastWord(int $number): string
    {
        if ($number < 1) {
            throw new InvalidArgumentException("Ordinals start at 1, got {$number}.");
        }

        return match (true) {
            $number % 10 !== 0 => self::UNITS[$number % 10],
            $number % 100 !== 0 => self::TENS[intdiv($number % 100, 10)],
            $number % 1000 !== 0 => 'yüz',
            $number % 10 ** 6 !== 0 => 'min',
            $number % 10 ** 9 !== 0 => 'milyon',
            $number % 10 ** 12 !== 0 => 'milyard',
            $number % 10 ** 15 !== 0 => 'trilyon',
            $number % 10 ** 18 !== 0 => 'kvadrilyon',
            default => 'kvintilyon',
        };
    }
}
