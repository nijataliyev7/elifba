<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;
use Illuminate\Filesystem\Filesystem;

/**
 * Deletes the clips no prompt or phrase needs any more (a word left a pack,
 * a template or the voice changed; docs/architecture.md §3 "Voice") and
 * writes the clip list again. Only `php artisan content:voice --prune` runs
 * it: a clip accepted by ear is never deleted by a run that makes clips.
 */
final readonly class PruneVoiceClips
{
    public function __construct(
        private ContentRepository $content,
        private VoiceClips $clips,
        private WriteVoiceClipList $list,
        private Filesystem $files,
    ) {}

    /**
     * @return list<string> the files deleted, relative to `content/assets`
     */
    public function handle(): array
    {
        $needed = $this->clips->needed($this->content);
        $folder = $this->content->root().'/assets/'.VoiceClips::FOLDER;
        $deleted = [];

        foreach ($this->files->isDirectory($folder) ? $this->files->files($folder) : [] as $file) {
            if (! isset($needed[$file->getBasename('.'.VoiceClips::EXTENSION)]) || $file->getExtension() !== VoiceClips::EXTENSION) {
                $this->files->delete($file->getPathname());
                $deleted[] = VoiceClips::FOLDER.'/'.$file->getFilename();
            }
        }

        $this->list->handle();

        return $deleted;
    }
}
