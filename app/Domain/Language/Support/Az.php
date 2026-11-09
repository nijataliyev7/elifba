<?php

namespace App\Domain\Language\Support;

use Normalizer;

/**
 * Azerbaijani casing and normalization (docs/architecture.md §3).
 *
 * `mb_strtoupper('i')` gives `I` and `mb_strtolower('İ')` gives `i̇`; both are
 * wrong here. The letter pairs of `content/alphabet.json` (i ↔ İ, ı ↔ I) are
 * mapped first, then mbstring cases everything else.
 */
final class Az
{
    public static function upper(string $text): string
    {
        return mb_strtoupper(strtr($text, Alphabet::upperMap()));
    }

    public static function lower(string $text): string
    {
        return mb_strtolower(strtr($text, Alphabet::lowerMap()));
    }

    /**
     * The first letter of every word (split at spaces and hyphens) uppercase,
     * the rest lowercase: "ƏLİ" → "Əli", "gül-ayşə" → "Gül-Ayşə".
     */
    public static function title(string $text): string
    {
        $parts = preg_split('/([ -])/u', self::lower($text), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];

        return implode('', array_map(
            static fn (string $part): string => $part === '' ? '' : self::upper(mb_substr($part, 0, 1)).mb_substr($part, 1),
            $parts,
        ));
    }

    /**
     * Runs on every name a teacher types and every content string: valid
     * UTF-8, Unicode NFC, look-alikes replaced by the letter (Cyrillic Ә → Ə,
     * Ǝ → Ə, i + combining dot → i), invisible characters (zero-width
     * spaces and joiners, soft hyphens, BOM) removed, whitespace collapsed to
     * single spaces and trimmed.
     */
    public static function normalize(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');
        $text = Normalizer::normalize($text, Normalizer::FORM_C) ?: $text;
        $text = strtr($text, Alphabet::lookalikes());
        $text = preg_replace('/[\x{00AD}\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', $text) ?? $text;
        $text = preg_replace('/[\s\p{Z}]+/u', ' ', $text) ?? $text;

        return trim($text);
    }
}
