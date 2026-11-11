<?php

namespace Tests\Support;

use App\Domain\Content\Data\VoiceData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\VoiceClips;
use Illuminate\Contracts\Foundation\Application;

/**
 * The board's voice as a test needs it (docs/architecture.md §3 "Voice"):
 * the real `voice.json` with a clip list of every clip built-in content
 * needs, or of none, bound in the container in place of the files, so a
 * test does not depend on which clips have been made.
 */
final class Voice
{
    /**
     * @param  (callable(VoiceLineData): bool)|null  $made  which clips count as made; null = every one
     */
    public static function made(Application $app, ?callable $made = null): VoiceClips
    {
        $content = $app->make(ContentRepository::class);
        $voice = $content->voice();
        $lines = (new VoiceClips($voice))->needed($content);
        $clips = [];

        foreach ($lines as $hash => $line) {
            if ($made === null || $made($line)) {
                $clips[(string) $hash] = $line->text;
            }
        }

        $bound = new VoiceClips(new VoiceData(
            $voice->version,
            $voice->settings,
            $voice->styles,
            $voice->letters,
            $voice->numbers,
            $voice->respell,
            $voice->praise,
            $voice->retry,
            $voice->credit,
            $clips,
        ));
        $app->instance(VoiceClips::class, $bound);

        return $bound;
    }

    public static function none(Application $app): VoiceClips
    {
        return self::made($app, static fn (): bool => false);
    }
}
