<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;
use Illuminate\Filesystem\Filesystem;

/**
 * Writes `content/voice-clips.json` from the files of `content/assets/voice`
 * (docs/architecture.md §3 "Voice"): every clip content needs that has a
 * file, with the text it was made from, in the order of `VoiceClips::needed()`;
 * then the listed clips no prompt needs any more whose file is still there,
 * until `content:voice --prune` deletes them. A flat object, so the file is
 * already as the formatter writes it.
 */
final readonly class WriteVoiceClipList
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
        private Filesystem $files,
    ) {}

    /**
     * @return array<string, string> hash → text, as written
     */
    public function handle(): array
    {
        $list = [];

        foreach ($this->clips->needed($this->content) as $hash => $line) {
            if ($this->files->isFile($this->file((string) $hash))) {
                $list[(string) $hash] = $line->text;
            }
        }

        // The list on disk now, not the one this process started with.
        $listed = ContentRepository::voiceIn($this->content->root())['clips'];

        foreach (is_array($listed) ? $listed : [] as $hash => $text) {
            $hash = (string) $hash;

            if (! isset($list[$hash]) && is_string($text) && $this->files->isFile($this->file($hash))) {
                $list[$hash] = $text;
            }
        }

        $this->files->put(
            $this->content->root().'/'.ContentRepository::VOICE_CLIPS,
            json_encode(['clips' => (object) $list], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        return $list;
    }

    private function file(string $hash): string
    {
        return $this->content->root().'/assets/'.VoiceClips::path($hash);
    }
}
