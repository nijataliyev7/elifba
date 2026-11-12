<?php

namespace Tests\Support;

use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\NameVoices;
use App\Domain\Content\Support\VoiceClips;
use App\Domain\Language\Support\AzCollator;
use Illuminate\Support\Facades\File;

/**
 * Name clips for tests (ADR 016), never the real ones: a fixture content
 * root (`ContentFixture`) whose `names.json` lists some first names and
 * whose `content/voice-names` holds a fake clip for some of them, listed in
 * `voice-clips.json`'s `names` as `content:voice` would. `fake()` binds a
 * `NameVoices` over it, so the app says those names while everything else
 * (packs, levels, the other clips) stays the real content.
 */
final class NameClips
{
    /**
     * @param  list<string>  $names  the list, any order
     * @param  list<string>  $made  the names whose clip exists
     * @return string the content root
     */
    public static function root(array $names, array $made): string
    {
        $root = ContentFixture::make(['file' => ContentRepository::NAMES, 'set' => ['names' => AzCollator::sort(array_unique([...$names, ...$made]))]]);
        $clips = new VoiceClips((new ContentRepository($root))->voice());
        $listed = [];

        File::ensureDirectoryExists($root.'/'.NameVoices::FOLDER);

        foreach ($made as $name) {
            $line = NameVoices::lineIn($clips, $name);
            File::put($root.'/'.NameVoices::path($line->hash), self::audio($line->hash));
            $listed[$line->hash] = $line->text;
        }

        $list = json_decode((string) file_get_contents($root.'/'.ContentRepository::VOICE_CLIPS), true, 8, JSON_THROW_ON_ERROR);
        $list['names'] = (object) $listed;
        File::put($root.'/'.ContentRepository::VOICE_CLIPS, json_encode($list, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return $root;
    }

    /**
     * Binds a `NameVoices` that says `$made` (and lists `$names` besides).
     *
     * @param  list<string>  $made
     * @param  list<string>  $names
     */
    public static function fake(array $made, array $names = []): NameVoices
    {
        $content = new ContentRepository(self::root($names, $made));
        $voices = new NameVoices($content, new VoiceClips($content->voice()));
        app()->instance(NameVoices::class, $voices);

        return $voices;
    }

    /**
     * What a fake clip holds: never the name, so a test that looks for a
     * name in a response cannot find it in the audio.
     */
    public static function audio(string $hash): string
    {
        return "ID3 fake clip {$hash}";
    }
}
