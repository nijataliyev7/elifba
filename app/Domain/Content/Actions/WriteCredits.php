<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\CreditsDocument;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes `CREDITS.md` at the repository root from `content/credits.json`
 * (docs/architecture.md §8), always from the JSON file, never from a
 * compiled cache. The caller has validated the content first.
 */
final readonly class WriteCredits
{
    public function __construct(
        private ContentRepository $content,
        private Filesystem $files,
    ) {}

    /**
     * @return string the path written
     */
    public function handle(): string
    {
        $root = $this->content->root();
        $path = self::path($root);

        $this->files->put($path, CreditsDocument::render((new ContentRepository($root))->credits()));

        return $path;
    }

    /**
     * `CREDITS.md` beside the content root, at the repository root.
     */
    public static function path(string $root): string
    {
        return dirname($root).'/CREDITS.md';
    }
}
