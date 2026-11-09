<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Actions\WriteCredits;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use Illuminate\Console\Command;

/**
 * Validates `content/` and writes `CREDITS.md` from `content/credits.json`
 * (docs/architecture.md §8). Run it after changing the credits; a test fails
 * while the file is stale.
 */
final class WriteCreditsCommand extends Command
{
    protected $signature = 'content:credits';

    protected $description = 'Validate content/ and write CREDITS.md from content/credits.json';

    public function handle(ContentRepository $content, WriteCredits $write): int
    {
        $problems = (new ContentValidator($content->root()))->validate();

        if ($problems !== []) {
            $this->components->error('CREDITS.md was not written: '.count($problems).' content problem(s).');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $path = $write->handle();
        $this->components->info(basename($path).' written.');

        return self::SUCCESS;
    }
}
