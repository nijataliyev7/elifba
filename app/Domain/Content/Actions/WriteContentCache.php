<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Support\ContentRepository;
use Illuminate\Filesystem\Filesystem;
use LogicException;

/**
 * Compiles the content JSON files into one PHP array at the repository's
 * compiled path (`bootstrap/cache/content.php`), which OPcache keeps in
 * memory like `config:cache` (docs/architecture.md §3). The caller has
 * validated the content first.
 */
final readonly class WriteContentCache
{
    public function __construct(
        private ContentRepository $content,
        private Filesystem $files,
    ) {}

    /**
     * @return string the path written
     *
     * @throws LogicException when the repository has no compiled path (tests)
     */
    public function handle(): string
    {
        $path = $this->content->compiledPath() ?? throw new LogicException('This content repository reads no compiled file.');

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->replace($path, '<?php return '.var_export($this->content->sources(), true).';'.PHP_EOL);

        return $path;
    }
}
