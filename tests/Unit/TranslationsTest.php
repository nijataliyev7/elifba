<?php

use App\Support\Translations;

/*
 * Translation parity (docs/architecture.md §9). Azerbaijani is the base: every
 * other language has exactly its keys, no empty strings and the same
 * placeholders, so a string added in one language only fails here first.
 */

const BASE_LOCALE = 'az';

/**
 * @return list<string>
 */
function shippedJsonLocales(): array
{
    return array_map(
        static fn (string $path): string => basename($path, '.json'),
        glob(dirname(__DIR__, 2).'/lang/*.json') ?: [],
    );
}

/**
 * @return list<string>
 */
function shippedPhpLocales(): array
{
    return array_map(
        static fn (string $path): string => basename($path),
        glob(dirname(__DIR__, 2).'/lang/*', GLOB_ONLYDIR) ?: [],
    );
}

/**
 * @return list<string> file names such as `validation.php`
 */
function phpTranslationFiles(string $locale): array
{
    return array_map(
        static fn (string $path): string => basename($path),
        glob(dirname(__DIR__, 2)."/lang/{$locale}/*.php") ?: [],
    );
}

/**
 * Flattens a PHP translation file to dotted keys.
 *
 * @param  array<array-key, mixed>  $lines
 * @return array<string, string>
 */
function flattenTranslations(array $lines, string $prefix = ''): array
{
    $flat = [];

    foreach ($lines as $key => $value) {
        if (is_array($value)) {
            $flat += flattenTranslations($value, "{$prefix}{$key}.");
        } else {
            $flat["{$prefix}{$key}"] = (string) $value;
        }
    }

    return $flat;
}

/**
 * Placeholder names, ignoring Laravel's capitalised variants (`:Attribute`).
 *
 * @return list<string>
 */
function placeholdersIn(string $value): array
{
    preg_match_all('/:([A-Za-z_]+)/', $value, $matches);
    $names = array_values(array_unique(array_map(strtolower(...), $matches[1])));
    sort($names);

    return $names;
}

/**
 * Every shipped language but the base. Read from the files, because datasets
 * are built before the application boots; the first test ties the files to
 * config('elifba.locales').
 *
 * @return list<string>
 */
function otherLocales(): array
{
    return array_values(array_diff(shippedJsonLocales(), [BASE_LOCALE]));
}

it('offers exactly the languages that ship translation files', function (): void {
    expect(array_keys(Translations::locales()))
        ->toEqualCanonicalizing(shippedJsonLocales())
        ->toEqualCanonicalizing(shippedPhpLocales())
        ->and(array_key_first(Translations::locales()))->toBe(BASE_LOCALE)
        ->and(config('app.locale'))->toBe(BASE_LOCALE)
        ->and(config('app.fallback_locale'))->toBe('en');
});

it('ships every interface string in every language', function (string $locale): void {
    $base = Translations::load(BASE_LOCALE);
    $translated = Translations::load($locale);

    expect($base)->not->toBeEmpty()
        ->and(array_values(array_diff(array_keys($base), array_keys($translated))))->toBe([], "Missing in {$locale}.json")
        ->and(array_values(array_diff(array_keys($translated), array_keys($base))))->toBe([], "Unknown keys in {$locale}.json (add them to az.json first)");
})->with(otherLocales());

it('has no empty strings', function (string $locale): void {
    $empty = array_keys(array_filter(Translations::load($locale), static fn (string $value): bool => trim($value) === ''));

    expect($empty)->toBe([], "Empty strings in {$locale}.json");
})->with(shippedJsonLocales());

it('keeps placeholders identical across languages', function (string $locale): void {
    $translated = Translations::load($locale);

    foreach (Translations::load(BASE_LOCALE) as $key => $value) {
        expect(placeholdersIn($translated[$key] ?? ''))->toBe(placeholdersIn($value), "{$locale}.json: {$key}");
    }
})->with(otherLocales());

it('ships the same framework files and keys in every language', function (string $locale): void {
    expect(phpTranslationFiles($locale))->toEqualCanonicalizing(phpTranslationFiles(BASE_LOCALE));

    foreach (phpTranslationFiles(BASE_LOCALE) as $file) {
        $base = flattenTranslations(require lang_path(BASE_LOCALE.'/'.$file));
        $translated = flattenTranslations(require lang_path("{$locale}/{$file}"));

        expect(array_keys($translated))->toEqualCanonicalizing(array_keys($base), "{$locale}/{$file}");

        foreach ($base as $key => $value) {
            expect(trim($translated[$key]))->not->toBe('', "{$locale}/{$file}: {$key} is empty")
                ->and(placeholdersIn($translated[$key]))->toBe(placeholdersIn($value), "{$locale}/{$file}: {$key}");
        }
    }
})->with(otherLocales());
