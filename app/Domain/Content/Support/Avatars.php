<?php

namespace App\Domain\Content\Support;

use JsonException;
use RuntimeException;

/**
 * The avatars from `content/avatars.json` (docs/architecture.md §3, §7, §8):
 * a key that fits `students.avatar` and a CC0 animal picture under
 * `content/assets` (Kenney's "Animal Pack Redux"), in the order new students
 * get them. `ContentValidator` checks the file; this class trusts it enough
 * to read keys and paths.
 */
final class Avatars
{
    public const KEY_PATTERN = '/^[a-z][a-z0-9_-]{0,31}$/';

    /**
     * @var non-empty-array<string, string>|null key → picture path under content/assets
     */
    private static ?array $images = null;

    /**
     * Every avatar key, in the file's order (the order new students get them).
     *
     * @return non-empty-list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::images());
    }

    public static function exists(string $key): bool
    {
        return isset(self::images()[$key]);
    }

    /**
     * The picture of one avatar as a path under `content/assets`; null for a
     * key that is no longer in the file.
     */
    public static function image(string $key): ?string
    {
        return self::images()[$key] ?? null;
    }

    /**
     * Every avatar's picture by key, as a path under `content/assets`.
     *
     * @return non-empty-array<string, string>
     */
    public static function images(): array
    {
        return self::$images ??= self::load(base_path('content/avatars.json'));
    }

    /**
     * Every avatar with its picture's URL, in the file's order: what a page
     * that draws avatars receives.
     *
     * @return non-empty-list<array{key: string, url: string}>
     */
    public static function all(): array
    {
        $avatars = [];

        foreach (self::images() as $key => $image) {
            $avatars[] = ['key' => $key, 'url' => ContentAssets::url($image)];
        }

        return $avatars;
    }

    /**
     * @return non-empty-array<string, string>
     */
    private static function load(string $path): array
    {
        $json = @file_get_contents($path);

        if ($json === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        try {
            $decoded = json_decode($json, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("{$path} is not valid JSON.", previous: $exception);
        }

        if (! is_array($decoded) || ! is_array($decoded['avatars'] ?? null)) {
            throw new RuntimeException("{$path} has no avatars.");
        }

        $images = [];

        foreach ($decoded['avatars'] as $index => $avatar) {
            $key = is_array($avatar) ? ($avatar['key'] ?? null) : null;
            $image = is_array($avatar) ? ($avatar['image'] ?? null) : null;

            if (! is_string($key) || preg_match(self::KEY_PATTERN, $key) !== 1 || isset($images[$key])) {
                throw new RuntimeException("{$path}: avatar #{$index} needs a unique `key` matching ".self::KEY_PATTERN.'.');
            }

            if (! is_string($image) || $image === '') {
                throw new RuntimeException("{$path}: avatar \"{$key}\" needs an `image`.");
            }

            $images[$key] = $image;
        }

        if ($images === []) {
            throw new RuntimeException("{$path} has no avatars.");
        }

        return $images;
    }
}
