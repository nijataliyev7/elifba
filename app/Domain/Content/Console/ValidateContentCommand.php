<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Content\Support\VoiceClips;
use Illuminate\Console\Command;

/**
 * Prints every problem in `content/` (docs/architecture.md §3). The Pest
 * suite runs the same validator. Valid content also gets two lines on the
 * voice (§3 "Voice"): how many of the clips it can ask for are made, and
 * how many names of the built-in name list have their clip (ADR 016); a
 * prompt or a name without one is silent, not a problem.
 */
final class ValidateContentCommand extends Command
{
    protected $signature = 'content:validate';

    protected $description = 'Check the alphabet, levels, prompts, credits, theme packs and syllable tests in content/';

    public function handle(ContentRepository $content, VoiceClips $voice, NameVoices $names): int
    {
        $problems = (new ContentValidator($content->root()))->validate();

        if ($problems !== []) {
            $this->components->error(count($problems).' content problem(s):');
            $this->components->bulletList($problems);

            return self::FAILURE;
        }

        $this->components->info('Content is valid.');

        $needed = $voice->needed($content);
        $silent = count(array_filter($needed, static fn (VoiceLineData $line): bool => $voice->url($line) === null));
        $made = count($needed) - $silent;
        $this->components->twoColumnDetail('Voice clips made', "{$made} of ".count($needed));

        if ($silent > 0) {
            $this->components->warn("{$silent} prompts and phrases are silent until `php artisan content:voice` makes their clips.");
        }

        $listed = $names->needed();
        $unvoiced = count(array_filter($listed, static fn (VoiceLineData $line): bool => ! isset($voice->voice()->names[$line->hash])));
        $this->components->twoColumnDetail('Name clips made', (count($listed) - $unvoiced).' of '.count($listed));

        if ($unvoiced > 0) {
            $this->components->warn("{$unvoiced} names of names.json are silent until `php artisan content:voice --only=name` makes their clips.");
        }

        return self::SUCCESS;
    }
}
