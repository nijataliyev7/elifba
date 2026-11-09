<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Actions\WriteContentCache;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use Illuminate\Console\Command;

/**
 * Validates `content/` and compiles it to `bootstrap/cache/content.php`;
 * `php artisan optimize` runs it on deploy (docs/architecture.md §3). Invalid
 * content is never compiled.
 */
final class CacheContentCommand extends Command
{
    protected $signature = 'content:cache';

    protected $description = 'Validate content/ and compile it into one cached file';

    public function handle(ContentRepository $content, WriteContentCache $write): int
    {
        $problems = (new ContentValidator($content->root()))->validate();

        if ($problems !== []) {
            $this->components->error('Content was not cached: '.count($problems).' problem(s).');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $write->handle();
        $this->components->info('Content cached successfully.');

        return self::SUCCESS;
    }
}
