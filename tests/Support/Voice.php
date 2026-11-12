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
 * needs (the greeting and the end phrases included), or of none, bound in
 * the container in place of the files, so a test does not depend on which
 * clips have been made. Name clips are `NameClips`'.
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
            version: $voice->version,
            settings: $voice->settings,
            styles: $voice->styles,
            letters: $voice->letters,
            numbers: $voice->numbers,
            respell: $voice->respell,
            praise: $voice->praise,
            retry: $voice->retry,
            credit: $voice->credit,
            clips: $clips,
            greeting: $voice->greeting,
            finish: $voice->finish,
            finishClass: $voice->finishClass,
            nameStyle: $voice->nameStyle,
            nameCredit: $voice->nameCredit,
            names: $voice->names,
        ));
        $app->instance(VoiceClips::class, $bound);

        return $bound;
    }

    public static function none(Application $app): VoiceClips
    {
        return self::made($app, static fn (): bool => false);
    }
}
