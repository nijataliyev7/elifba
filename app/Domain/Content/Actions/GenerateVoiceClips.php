<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ElevenLabs;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Content\Support\VoiceLeveler;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Makes the clips of the board's voice that have no file yet
 * (docs/architecture.md §3 "Voice", ADR 015), one request each, and writes
 * them to `content/assets/voice/{hash}.mp3` levelled (`VoiceLeveler`). Run by
 * `php artisan content:voice` on the machine that commits the files, never
 * during a request. Whatever happens, the clip list is written for the
 * files made so far, so a run stopped half way loses nothing: the next one
 * makes only the rest.
 *
 * The first clip also gets the folder its credits entry, from `voice.json`'s
 * `credit` with the month of generation, and `CREDITS.md` is written again.
 */
final readonly class GenerateVoiceClips
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
        private VoiceLeveler $leveler,
        private WriteVoiceClipList $list,
        private WriteCredits $credits,
        private Filesystem $files,
    ) {}

    /**
     * The clips content needs that have no file yet, in order.
     *
     * @return list<VoiceLineData>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->clips->needed($this->content),
            fn (VoiceLineData $line): bool => ! $this->files->isFile($this->file($line->hash)),
        ));
    }

    /**
     * @param  list<VoiceLineData>  $lines  clips without a file (`missing()`)
     * @param  (callable(VoiceLineData, int): void)|null  $made  told of each file written, with its size in bytes
     * @return bool whether the folder got its credits entry now
     *
     * @throws RuntimeException when the provider or ffmpeg fails; the clips made until then are kept and listed
     */
    public function handle(ElevenLabs $provider, array $lines, ?callable $made = null): bool
    {
        $settings = $this->clips->voice()->settings;
        $this->files->ensureDirectoryExists($this->content->root().'/assets/'.VoiceClips::FOLDER);
        $any = false;

        try {
            foreach ($lines as $line) {
                $audio = $this->leveler->level($provider->speak($settings, $line->text));
                $this->files->put($this->file($line->hash), $audio);
                $any = true;

                if ($made !== null) {
                    $made($line, strlen($audio));
                }
            }
        } finally {
            $this->list->handle();
            $credited = $any && $this->credit();
        }

        return $credited;
    }

    /**
     * Adds the folder's credits entry when `credits.json` has none, as the
     * last entry, laid out as the formatter writes it.
     *
     * @return bool whether it was added
     */
    private function credit(): bool
    {
        $pattern = basename($this->content->root()).'/assets/'.VoiceClips::FOLDER.'/*';
        $path = $this->content->root().'/credits.json';
        $existing = array_filter((new ContentRepository($this->content->root()))->credits(), static fn (CreditData $credit): bool => in_array($pattern, $credit->files, true));

        if ($existing !== []) {
            return false;
        }

        $credit = $this->clips->voice()->credit;
        $credit['changes'] = str_replace('{month}', CarbonImmutable::now(config('elifba.timezone'))->format('F Y'), $credit['changes']);
        $json = $this->files->get($path);
        $end = strrpos($json, ']');
        $last = $end === false ? false : strrpos(substr($json, 0, $end), '}');

        if ($last === false) {
            throw new RuntimeException('credits.json has no list of credits to add the voice to.');
        }

        $encode = static fn (string $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $entry = ",\n        {\n"
            .'            "files": ['.$encode($pattern)."],\n"
            .'            "title": '.$encode($credit['title']).",\n"
            .'            "author": '.$encode($credit['author']).",\n"
            .'            "source": '.$encode($credit['source']).",\n"
            .'            "license": '.$encode($credit['license']).",\n"
            .'            "changes": '.$encode($credit['changes'])."\n"
            .'        }';

        $this->files->put($path, substr($json, 0, $last + 1).$entry.substr($json, $last + 1));
        $this->credits->handle();

        return true;
    }

    private function file(string $hash): string
    {
        return $this->content->root().'/assets/'.VoiceClips::path($hash);
    }
}
