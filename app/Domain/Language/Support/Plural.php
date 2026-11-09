<?php

namespace App\Domain\Language\Support;

use MessageFormatter;

/**
 * Plural forms of interface strings on the server, written as React reads
 * them (`useTranslation()`, docs/frontend.md §2): "{one}:count point|{other}
 * :count points". The form is the language's CLDR plural category of the
 * count, from ICU — the same rules as the browser's `Intl.PluralRules`.
 * Azerbaijani keeps the noun singular after a number, so its forms are
 * usually equal. Laravel's `trans_choice()` does not read this syntax.
 */
final class Plural
{
    private const string CATEGORIES = '{count, plural, zero{zero} one{one} two{two} few{few} many{many} other{other}}';

    /**
     * The form of the line for the count; the line itself when it has no
     * `{category}` forms. Placeholders are left as they are.
     */
    public static function choose(string $line, int $count, string $locale): string
    {
        $forms = [];

        foreach (explode('|', $line) as $form) {
            if (preg_match('/^\{(zero|one|two|few|many|other)\}(.*)$/s', $form, $match) === 1) {
                $forms[$match[1]] = $match[2];
            }
        }

        if ($forms === []) {
            return $line;
        }

        return $forms[self::category($count, $locale)] ?? $forms['other'] ?? $line;
    }

    /**
     * The CLDR plural category of the count in the language: "one" or
     * "other" in az and en.
     */
    public static function category(int $count, string $locale): string
    {
        $category = MessageFormatter::formatMessage($locale, self::CATEGORIES, ['count' => $count]);

        return is_string($category) ? $category : 'other';
    }
}
