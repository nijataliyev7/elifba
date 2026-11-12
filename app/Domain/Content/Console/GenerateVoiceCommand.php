<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Actions\GenerateVoiceClips;
use App\Domain\Content\Actions\PruneVoiceClips;
use App\Domain\Content\Actions\WriteVoiceReview;
use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ContentValidator;
use App\Domain\Content\Support\ElevenLabs;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Content\Support\VoiceLeveler;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Makes the board's voice (docs/architecture.md §3 "Voice", ADR 015): every
 * clip built-in content can ask for that has no file yet, from ElevenLabs,
 * levelled by ffmpeg, into `content/assets/voice`, and every first name of
 * the built-in name list (`content/names.json`, ADR 016; the group `name`,
 * `--only=name`) into `content/voice-names`, with the clip list and, the
 * first time, each folder's credits entry. Nothing already made is made
 * again. `--dry-run` says what a run would make and how many characters it
 * sends, and calls nothing; a real run asks first (or `--force`) and writes
 * a page to listen to the clips on, outside the repository.
 *
 * The key is `ELEVENLABS_API_KEY` (`config('elifba.voice.api_key')`), in the
 * shell or the gitignored `.env` of the machine that makes the clips; this
 * command never prints it. Only content files are voiced: nothing is read
 * from the database, so no student's or child's name and no teacher word
 * can reach the provider; the names it sends are the list's.
 */
final class GenerateVoiceCommand extends Command
{
    protected $signature = 'content:voice
        {--dry-run : Say what a run would make and how many characters it sends; call nothing}
        {--limit= : Make at most this many clips (a sample to listen to first)}
        {--only= : Make only these groups, comma separated (find.picture,praise,retry,greeting,finish,finish_class,name)}
        {--prune : Delete the clips no prompt, phrase or listed name needs any more, and make none}
        {--review= : Where to write the page to listen to the clips on (default: the system temp folder)}
        {--force : Do not ask before calling the paid API}';

    protected $description = 'Make the clips of the board\'s voice and of the built-in name list that have no file yet (ElevenLabs, a paid plan; ADR 015, ADR 016)';

    public function handle(ContentRepository $content, VoiceClips $clips, GenerateVoiceClips $generate, PruneVoiceClips $prune, WriteVoiceReview $review, VoiceLeveler $leveler): int
    {
        $problems = (new ContentValidator($content->root(), voiceClipsRequired: false))->validate();

        if ($problems !== []) {
            $this->components->error('No clip was made: '.count($problems).' content problem(s).');
            $this->components->bulletList($problems);
            $this->suggestVoices($content, $problems);

            return self::FAILURE;
        }

        if ($this->option('prune') === true) {
            $deleted = $prune->handle();
            $this->components->info(count($deleted) === 0 ? 'Every clip is still needed.' : 'Deleted '.count($deleted).' clip(s) no prompt, phrase or listed name needs.');
            $this->components->bulletList($deleted);

            return self::SUCCESS;
        }

        $needed = $generate->needed();
        $missing = $generate->missing();
        $lines = $this->chosen($missing);

        $this->report($needed, $missing, $lines);
        $this->expectedCredit($content, $clips);

        if ($this->option('dry-run') === true) {
            $this->components->info('Dry run: nothing was sent.');

            return self::SUCCESS;
        }

        if ($lines === []) {
            $this->components->info('There is no clip to make.');

            return self::SUCCESS;
        }

        $key = config('elifba.voice.api_key');

        if (! is_string($key) || $key === '') {
            $this->components->error('Set ELEVENLABS_API_KEY in your shell or in .env (never in .env.example), then run this again.');

            return self::FAILURE;
        }

        if (! $leveler->available()) {
            $this->components->error('ffmpeg is needed to level the clips (brew install ffmpeg).');

            return self::FAILURE;
        }

        if ($this->option('force') !== true && ! $this->confirm('Make '.count($lines).' clip(s), '.self::characters($lines).' characters, with ElevenLabs (paid plan)?')) {
            $this->components->info('Nothing was sent.');

            return self::SUCCESS;
        }

        $fresh = [];
        $bytes = 0;
        $status = self::SUCCESS;
        $credited = [];
        $provider = new ElevenLabs((string) config('elifba.voice.api_url'), $key);

        try {
            $credited = $generate->handle($provider, $lines, function (VoiceLineData $line, int $size) use (&$fresh, &$bytes, $lines): void {
                $fresh[] = $line->hash;
                $bytes += $size;
                $this->components->twoColumnDetail(sprintf('%d/%d  %s', count($fresh), count($lines), $line->phrase), sprintf('%s  %.1f KB', $line->hash, $size / 1024));
            });
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());
            $status = self::FAILURE;
        }

        $this->components->info(sprintf('Made %d clip(s), %.1f KB; voice-clips.json lists every clip with a file.', count($fresh), $bytes / 1024));

        foreach ($credited as $pattern) {
            $this->components->info("credits.json got the entry for {$pattern} and CREDITS.md was written again.");
        }

        if ($fresh !== []) {
            $page = is_string($this->option('review')) && $this->option('review') !== '' ? $this->option('review') : sys_get_temp_dir().'/elifba-voice-review.html';
            $review->handle($page, $fresh);
            $this->components->info("Listen to every clip before committing: {$page}");
        }

        return $status;
    }

    /**
     * The missing clips `--only` and `--limit` leave.
     *
     * @param  list<VoiceLineData>  $missing
     * @return list<VoiceLineData>
     */
    private function chosen(array $missing): array
    {
        $only = $this->option('only');

        if (is_string($only) && $only !== '') {
            $groups = array_map(trim(...), explode(',', $only));
            $missing = array_values(array_filter($missing, static fn (VoiceLineData $line): bool => in_array($line->group, $groups, true)));
        }

        $limit = $this->option('limit');

        return is_numeric($limit) ? array_slice($missing, 0, max(0, (int) $limit)) : $missing;
    }

    /**
     * Per group: the clips content needs, those made, those this run makes
     * and the characters it sends for them, which is what the plan counts.
     *
     * @param  list<VoiceLineData>  $needed
     * @param  list<VoiceLineData>  $missing
     * @param  list<VoiceLineData>  $lines
     */
    private function report(array $needed, array $missing, array $lines): void
    {
        $count = static function (array $lines): array {
            $counts = [];

            foreach ($lines as $line) {
                $counts[$line->group] = ($counts[$line->group] ?? 0) + 1;
            }

            return $counts;
        };
        $all = $count($needed);
        $open = $count($missing);
        $run = $count($lines);
        $rows = [];

        foreach ($all as $group => $total) {
            $sent = array_filter($lines, static fn (VoiceLineData $line): bool => $line->group === $group);
            $rows[] = [$group, $total, $total - ($open[$group] ?? 0), $run[$group] ?? 0, self::characters(array_values($sent))];
        }

        $rows[] = ['All', count($needed), count($needed) - count($missing), count($lines), self::characters($lines)];

        $this->table(['Group', 'Needed', 'Made', 'This run', 'Characters'], $rows);
        $this->line(sprintf(
            '  This run: %d clip(s), %d characters sent (%d of them the phrases, %d the audio tags), which is what ElevenLabs counts.',
            count($lines),
            self::characters($lines),
            array_sum(array_map(static fn (VoiceLineData $line): int => mb_strlen($line->phrase), $lines)),
            self::characters($lines) - array_sum(array_map(static fn (VoiceLineData $line): int => mb_strlen($line->phrase), $lines)),
        ));
    }

    /**
     * The credits entry each folder gets with its first clip, when it has
     * none: the clips' (`credit`) and the name clips' (`names.credit`).
     */
    private function expectedCredit(ContentRepository $content, VoiceClips $clips): void
    {
        foreach ([[false, $clips->voice()->credit], [true, $clips->voice()->nameCredit]] as [$names, $credit]) {
            $pattern = GenerateVoiceClips::pattern($content, $names);

            if (array_filter($content->credits(), static fn (CreditData $entry): bool => in_array($pattern, $entry->files, true)) !== []) {
                $this->components->twoColumnDetail('credits.json', "has the entry for {$pattern}");

                continue;
            }

            $credit['changes'] = str_replace('{month}', CarbonImmutable::now(config('elifba.timezone'))->format('F Y'), $credit['changes']);

            $this->components->twoColumnDetail('credits.json', 'gets this entry with the first '.($names ? 'name clip' : 'clip').', and CREDITS.md is written again:');
            $this->line(json_encode(['files' => [$pattern], ...$credit], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    /**
     * When `voice.json` has no usable voice id and a key is set, the
     * account's voices whose name shares the first word of `voice_name`.
     *
     * @param  list<string>  $problems
     */
    private function suggestVoices(ContentRepository $content, array $problems): void
    {
        $key = config('elifba.voice.api_key');

        if (! is_string($key) || $key === '' || array_filter($problems, static fn (string $problem): bool => str_contains($problem, 'voice.voice_id')) === []) {
            return;
        }

        try {
            $name = ContentRepository::voiceIn($content->root())['voice']['voice_name'] ?? '';
            $voices = (new ElevenLabs((string) config('elifba.voice.api_url'), $key))->voices();
        } catch (RuntimeException $exception) {
            $this->components->warn($exception->getMessage());

            return;
        }

        $first = mb_strtolower(explode(' ', is_string($name) ? $name : '')[0]);
        $matching = array_filter($voices, static fn (string $voice): bool => $first !== '' && str_contains(mb_strtolower($voice), $first));

        $this->components->info('Voices of the account'.($matching === [] ? '' : " named like \"{$first}\"").'; put the id in voice.json:');
        $this->table(['voice_id', 'name'], array_map(null, array_keys($matching ?: $voices), array_values($matching ?: $voices)));
    }

    /**
     * @param  list<VoiceLineData>  $lines
     */
    private static function characters(array $lines): int
    {
        return array_sum(array_map(static fn (VoiceLineData $line): int => mb_strlen($line->text), $lines));
    }
}
