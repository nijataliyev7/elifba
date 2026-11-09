<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use Illuminate\Console\Command;

/**
 * Prints every problem in `content/` (docs/architecture.md §3). The Pest
 * suite runs the same validator.
 */
final class ValidateContentCommand extends Command
{
    protected $signature = 'content:validate';

    protected $description = 'Check the alphabet, levels, prompts, credits, theme packs and syllable tests in content/';

    public function handle(ContentRepository $content): int
    {
        $problems = (new ContentValidator($content->root()))->validate();

        if ($problems !== []) {
            $this->components->error(count($problems).' content problem(s):');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $this->components->info('Content is valid.');

        return self::SUCCESS;
    }
}
