<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;
use Illuminate\Filesystem\Filesystem;

/**
 * The page the owner listens to every clip on before the files are
 * committed (ADR 015, implementation outline): one row per clip with its
 * group, the phrase, the text the provider was given, the hash (to delete a
 * bad clip, which the next run makes again) and a player for the file on
 * disk. The clips made in the last run come first. It is written outside
 * the repository and loads nothing from anywhere.
 */
final readonly class WriteVoiceReview
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
        private Filesystem $files,
    ) {}

    /**
     * @param  list<string>  $fresh  hashes of the clips made in this run
     */
    public function handle(string $path, array $fresh): void
    {
        $rows = [];
        $isFresh = array_flip($fresh);
        $lines = array_filter(
            $this->clips->needed($this->content),
            fn (VoiceLineData $line): bool => $this->files->isFile($this->file($line->hash)),
        );

        usort($lines, static fn (VoiceLineData $a, VoiceLineData $b): int => (isset($isFresh[$b->hash]) <=> isset($isFresh[$a->hash])));

        foreach ($lines as $line) {
            $rows[] = sprintf(
                '<tr%s><td>%s</td><td lang="az"><b>%s</b><br><small>%s</small></td><td><audio controls preload="none" src="%s"></audio></td><td><code>%s</code></td></tr>',
                isset($isFresh[$line->hash]) ? ' class="fresh"' : '',
                e($line->group),
                e($line->phrase),
                e($line->text),
                e('file://'.str_replace('%2F', '/', rawurlencode($this->file($line->hash)))),
                e($line->hash),
            );
        }

        $this->files->ensureDirectoryExists(dirname($path));
        $this->files->put($path, '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Elifba voice clips</title>'
            .'<style>body{font:15px system-ui,sans-serif;margin:24px}table{border-collapse:collapse}td{border-bottom:1px solid #ddd;padding:6px 10px;vertical-align:middle}'
            .'.fresh{background:#fff8e1}small{color:#666}</style></head><body>'
            .'<h1>Elifba voice clips</h1><p>'.count($lines).' clips; '.count($fresh).' made in the last run, highlighted first. '
            .'A bad clip: delete <code>content/assets/voice/{hash}.mp3</code> and run <code>php artisan content:voice</code> again, '
            .'or give the phrase a respelling in <code>voice.json</code>.</p>'
            .'<table>'.implode('', $rows).'</table></body></html>'.PHP_EOL);
    }

    private function file(string $hash): string
    {
        return $this->content->root().'/assets/'.VoiceClips::path($hash);
    }
}
