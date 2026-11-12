<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\CreditData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\ElevenLabs;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Content\Support\VoiceLeveler;
use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Makes the clips of the board's voice that have no file yet
 * (docs/architecture.md §3 "Voice", ADR 015), one request each, and writes
 * them levelled (`VoiceLeveler`): a prompt or phrase to
 * `content/assets/voice/{hash}.mp3`, a first name of the built-in name list
 * (ADR 016) to `content/voice-names/{hash}.mp3`, outside `content/assets`,
 * so no name clip is ever a public file. Run by `php artisan content:voice`
 * on the machine that commits the files, never during a request; the lines
 * come from content files only (`VoiceClips::needed()`,
 * `NameVoices::needed()`), never from a row. Whatever happens, the clip
 * list is written for the files made so far, so a run stopped half way
 * loses nothing: the next one makes only the rest.
 *
 * The first clip of a folder also gets the folder its credits entry, from
 * `voice.json`'s `credit` (or `names.credit`) with the month of
 * generation, and `CREDITS.md` is written again.
 */
final readonly class GenerateVoiceClips
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
        private NameVoices $names,
        private VoiceLeveler $leveler,
        private WriteVoiceClipList $list,
        private WriteCredits $credits,
        private Filesystem $files,
    ) {}

    /**
     * Every clip content needs: the prompts and phrases, then the names of
     * the list. A list, not a map by hash: a name may say what a phrase
     * says («Afərin!» is a praise phrase and a girl's name), and then each
     * has its own file in its own folder, the name's never public.
     *
     * @return list<VoiceLineData>
     */
    public function needed(): array
    {
        return [...array_values($this->clips->needed($this->content)), ...array_values($this->names->needed())];
    }

    /**
     * The clips content needs that have no file yet, in order.
     *
     * @return list<VoiceLineData>
     */
    public function missing(): array
    {
        return array_values(array_filter(
            $this->needed(),
            fn (VoiceLineData $line): bool => ! $this->files->isFile($this->file($line)),
        ));
    }

    /**
     * Where a clip's file lives: a name's under `content/voice-names`, every
     * other under `content/assets/voice`.
     */
    public function file(VoiceLineData $line): string
    {
        return $this->content->root().'/'.self::relative($line);
    }

    /**
     * A clip's file relative to the content root.
     */
    public static function relative(VoiceLineData $line): string
    {
        return $line->isName() ? NameVoices::path($line->hash) : 'assets/'.VoiceClips::path($line->hash);
    }

    /**
     * @param  list<VoiceLineData>  $lines  clips without a file (`missing()`)
     * @param  (callable(VoiceLineData, int): void)|null  $made  told of each file written, with its size in bytes
     * @return list<string> the folder patterns that got their credits entry now (`content/voice-names/*`)
     *
     * @throws RuntimeException when the provider or ffmpeg fails; the clips made until then are kept and listed
     */
    public function handle(ElevenLabs $provider, array $lines, ?callable $made = null): array
    {
        $settings = $this->clips->voice()->settings;
        $madeNames = false;
        $madeOthers = false;

        try {
            foreach ($lines as $line) {
                $audio = $this->leveler->level($provider->speak($settings, $line->text));
                $this->files->ensureDirectoryExists(dirname($this->file($line)));
                $this->files->put($this->file($line), $audio);
                $madeNames = $madeNames || $line->isName();
                $madeOthers = $madeOthers || ! $line->isName();

                if ($made !== null) {
                    $made($line, strlen($audio));
                }
            }
        } finally {
            $this->list->handle();
            $credited = array_values(array_filter([
                $madeOthers ? $this->credit(self::pattern($this->content, false), $this->clips->voice()->credit) : null,
                $madeNames ? $this->credit(self::pattern($this->content, true), $this->clips->voice()->nameCredit) : null,
            ]));
        }

        return $credited;
    }

    /**
     * The credits pattern of the folder of the clips or of the name clips:
     * `content/assets/voice/*`, `content/voice-names/*`.
     */
    public static function pattern(ContentRepository $content, bool $names): string
    {
        return basename($content->root()).'/'.($names ? NameVoices::FOLDER : 'assets/'.VoiceClips::FOLDER).'/*';
    }

    /**
     * Adds a folder's credits entry when `credits.json` has none, as the
     * last entry, laid out as the formatter writes it.
     *
     * @param  array{title: string, author: string, source: string, license: string, changes: string}  $credit
     * @return string|null the pattern when it was added
     */
    private function credit(string $pattern, array $credit): ?string
    {
        $path = $this->content->root().'/credits.json';
        $existing = array_filter((new ContentRepository($this->content->root()))->credits(), static fn (CreditData $entry): bool => in_array($pattern, $entry->files, true));

        if ($existing !== []) {
            return null;
        }

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

        return $pattern;
    }
}
