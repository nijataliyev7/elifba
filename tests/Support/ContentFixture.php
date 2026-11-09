<?php

namespace Tests\Support;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A throw-away content root for validator and repository tests: the valid
 * fixture in tests/Fixtures/content/base (with its own small avatars.json
 * and pictures) plus the real alphabet.json, copied to `{tmp}/content`
 * (credits name files from the folder above the root), then broken by one
 * patch from tests/Fixtures/content/broken.json:
 *
 * - `file` + `set` (dot path → value), `unset` (dot paths), `keep`
 *   (dot path → how many list items to keep) edit one JSON file;
 * - `write` (path → contents) and `delete` (paths) add or remove files.
 *
 * Paths are relative to the content root; `../resources/fonts/…` reaches
 * the fonts folder beside it. The folders are removed when PHP exits.
 */
final class ContentFixture
{
    /**
     * @var list<string>
     */
    private static array $made = [];

    /**
     * @param  array<string, mixed>  $patch
     * @return string the content root
     */
    public static function make(array $patch = []): string
    {
        if (self::$made === []) {
            register_shutdown_function(static fn () => array_map(File::deleteDirectory(...), self::$made));
        }

        $project = sys_get_temp_dir().'/elifba-content-'.Str::lower(Str::random(10));
        $root = "{$project}/content";
        self::$made[] = $project;

        File::copyDirectory(base_path('tests/Fixtures/content/base'), $root);
        File::copy(base_path('content/alphabet.json'), "{$root}/alphabet.json");

        if (isset($patch['set']) || isset($patch['unset']) || isset($patch['keep'])) {
            self::edit("{$root}/".self::text($patch['file'] ?? null), $patch);
        }

        foreach (self::map($patch['write'] ?? []) as $path => $contents) {
            File::ensureDirectoryExists(dirname("{$root}/{$path}"));
            File::put("{$root}/{$path}", self::text($contents));
        }

        foreach (self::map($patch['delete'] ?? []) as $path) {
            File::delete("{$root}/".self::text($path));
        }

        return $root;
    }

    /**
     * Every broken fixture by name: [patch, expected problem].
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function broken(): array
    {
        $cases = [];

        foreach (self::map(json_decode((string) file_get_contents(dirname(__DIR__).'/Fixtures/content/broken.json'), true, 64, JSON_THROW_ON_ERROR)) as $name => $case) {
            $case = self::map($case);
            $cases[(string) $name] = [$case, self::text($case['problem'] ?? null)];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private static function edit(string $path, array $patch): void
    {
        $data = self::map(json_decode(File::get($path), true, 64, JSON_THROW_ON_ERROR));

        foreach (self::map($patch['set'] ?? []) as $key => $value) {
            data_set($data, (string) $key, $value);
        }

        foreach (self::map($patch['unset'] ?? []) as $key) {
            Arr::forget($data, self::text($key));
        }

        foreach (self::map($patch['keep'] ?? []) as $key => $count) {
            data_set($data, (string) $key, array_slice(self::map(data_get($data, (string) $key)), 0, is_int($count) ? $count : 0));
        }

        File::put($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<array-key, mixed>
     */
    private static function map(mixed $value): array
    {
        return is_array($value) ? $value : throw new RuntimeException('A content fixture patch needs objects and lists here.');
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('A content fixture patch needs a string here.');
    }
}
