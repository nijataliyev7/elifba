<?php

namespace App\Domain\Content\Support;

/**
 * Public URLs of the files under `content/assets`, which `storage:link`
 * serves at `/content/…` (docs/architecture.md §3). Page props carry these
 * URLs, so TypeScript never builds an asset path.
 */
final class ContentAssets
{
    /**
     * The public folder that links to `content/assets` (`config/filesystems.php`).
     */
    public const string PUBLIC_FOLDER = 'content';

    /**
     * @param  string  $path  relative to `content/assets`, as content files write it (`avatars/panda.svg`)
     * @param  int|null  $version  the pack version, for cache busting (`?v=3`)
     */
    public static function url(string $path, ?int $version = null): string
    {
        $url = asset(self::PUBLIC_FOLDER.'/'.ltrim($path, '/'));

        return $version === null ? $url : "{$url}?v={$version}";
    }

    /**
     * The same file as a path from the host's root (`/content/avatars/panda.svg`),
     * for a page that must load it from its own origin whatever scheme or
     * host the server saw behind a proxy or a tunnel: the share card, whose
     * pictures `modern-screenshot` fetches and inlines (docs/architecture.md §6).
     *
     * @param  string  $path  relative to `content/assets`
     */
    public static function path(string $path): string
    {
        return (string) parse_url(self::url($path), PHP_URL_PATH);
    }
}
