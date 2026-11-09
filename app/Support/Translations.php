<?php

namespace App\Support;

use RuntimeException;

/**
 * The interface strings in `lang/{locale}.json`, shared with the React app
 * as the `translations` prop. Azerbaijani is the base language: a key missing
 * in another language falls back to `config('app.fallback_locale')`, and the
 * parity test fails before that can ship.
 */
final class Translations
{
    /**
     * @var array<string, array<string, string>>
     */
    private static array $loaded = [];

    /**
     * @return array<string, string> locale => native name
     */
    public static function locales(): array
    {
        /** @var array<string, string> $locales */
        $locales = config('elifba.locales', ['az' => 'Azərbaycanca']);

        return $locales;
    }

    public static function supports(mixed $locale): bool
    {
        return is_string($locale) && array_key_exists($locale, self::locales());
    }

    public static function default(): string
    {
        $locale = config('app.locale');

        return self::supports($locale) ? $locale : (string) array_key_first(self::locales());
    }

    /**
     * @return array<string, string>
     */
    public static function for(string $locale): array
    {
        $fallback = config('app.fallback_locale');
        $base = is_string($fallback) && $fallback !== $locale ? self::load($fallback) : [];

        return [...$base, ...self::load($locale)];
    }

    /**
     * @return array<string, string>
     */
    public static function load(string $locale): array
    {
        if (isset(self::$loaded[$locale])) {
            return self::$loaded[$locale];
        }

        $path = lang_path("{$locale}.json");

        if (! is_file($path)) {
            return self::$loaded[$locale] = [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            throw new RuntimeException("Invalid translation file: {$path}");
        }

        /** @var array<string, string> $decoded */
        return self::$loaded[$locale] = $decoded;
    }
}
