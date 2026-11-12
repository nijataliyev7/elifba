<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Content\Support\VoiceClips;
use Illuminate\Filesystem\Filesystem;

/**
 * Deletes the clips nothing needs any more (a word left a pack, a template
 * or the voice changed, a name left the built-in list;
 * docs/architecture.md §3 "Voice", ADR 016) from `content/assets/voice` and
 * `content/voice-names`, and writes the clip list again. Only
 * `php artisan content:voice --prune` runs it: a clip accepted by ear is
 * never deleted by a run that makes clips, and nothing about a student or a
 * child ever deletes one (a name clip is list content).
 */
final readonly class PruneVoiceClips
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
        private NameVoices $names,
        private WriteVoiceClipList $list,
        private Filesystem $files,
    ) {}

    /**
     * @return list<string> the files deleted, relative to the content root
     */
    public function handle(): array
    {
        $deleted = [
            ...$this->prune('assets/'.VoiceClips::FOLDER, $this->clips->needed($this->content)),
            ...$this->prune(NameVoices::FOLDER, $this->names->needed()),
        ];

        $this->list->handle();

        return $deleted;
    }

    /**
     * @param  array<string, VoiceLineData>  $needed
     * @return list<string>
     */
    private function prune(string $folder, array $needed): array
    {
        $path = $this->content->root().'/'.$folder;
        $deleted = [];

        foreach ($this->files->isDirectory($path) ? $this->files->files($path) : [] as $file) {
            if (! isset($needed[$file->getBasename('.'.VoiceClips::EXTENSION)]) || $file->getExtension() !== VoiceClips::EXTENSION) {
                $this->files->delete($file->getPathname());
                $deleted[] = $folder.'/'.$file->getFilename();
            }
        }

        return $deleted;
    }
}
