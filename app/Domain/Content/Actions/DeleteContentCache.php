<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Support\ContentRepository;
use Illuminate\Filesystem\Filesystem;

/**
 * Removes the compiled content file, so the JSON files are read again.
 */
final readonly class DeleteContentCache
{
    public function __construct(
        private ContentRepository $content,
        private Filesystem $files,
    ) {}

    /**
     * @return bool whether there was a file to delete
     */
    public function handle(): bool
    {
        $path = $this->content->compiledPath();

        return $path !== null && $this->files->exists($path) && $this->files->delete($path);
    }
}
