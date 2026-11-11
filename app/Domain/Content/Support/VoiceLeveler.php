<?php

namespace App\Domain\Content\Support;

use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

/**
 * Makes a clip from what the provider returned (docs/architecture.md §3
 * "Voice"): the silence before and after the phrase trimmed, so the board
 * speaks as soon as it means to; every clip at the same loudness (EBU R128
 * integrated, measured by ffmpeg's `loudnorm`, then one gain and a limiter
 * that only touches the peaks above TRUE_PEAK); mono MP3 without tags, like
 * the sounds (§8). Needs ffmpeg with `libmp3lame`.
 */
final class VoiceLeveler
{
    /**
     * Integrated loudness, LUFS: clear over the celebrations (about −13.5)
     * when it follows them, and well above the train (−20).
     */
    public const float LOUDNESS = -16.0;

    public const float TRUE_PEAK = -1.5;

    public const string BITRATE = '64k';

    /**
     * Quieter than this is silence, at the start and at the end; a little
     * of it is kept (in seconds), so no syllable is clipped.
     */
    private const string TRIM = 'silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.03,'
        .'areverse,silenceremove=start_periods=1:start_threshold=-50dB:start_silence=0.12,areverse';

    public function available(): bool
    {
        return Process::run(['ffmpeg', '-hide_banner', '-version'])->successful();
    }

    /**
     * @param  string  $audio  the provider's MP3
     * @return string the clip's MP3
     *
     * @throws RuntimeException when ffmpeg fails
     */
    public function level(string $audio): string
    {
        $measured = Process::input($audio)->timeout(60)->run([
            'ffmpeg', '-hide_banner', '-nostats', '-i', 'pipe:0',
            '-af', self::TRIM.',loudnorm=print_format=json',
            '-f', 'null', '-',
        ]);

        if ($measured->failed()) {
            throw new RuntimeException('ffmpeg could not measure the clip: '.self::lastLine($measured->errorOutput()));
        }

        $gain = self::LOUDNESS - (float) self::measurement($measured->errorOutput())['input_i'];
        $rendered = Process::input($audio)->timeout(60)->run([
            'ffmpeg', '-hide_banner', '-nostats', '-loglevel', 'error', '-i', 'pipe:0',
            '-af', self::TRIM.','.sprintf(
                'volume=%.2fdB,alimiter=limit=%.3f:attack=2:release=40:level=disabled,aresample=44100',
                $gain,
                10 ** (self::TRUE_PEAK / 20),
            ),
            '-ac', '1', '-c:a', 'libmp3lame', '-b:a', self::BITRATE,
            '-map_metadata', '-1', '-id3v2_version', '0', '-write_id3v1', '0',
            '-f', 'mp3', 'pipe:1',
        ]);

        if ($rendered->failed() || $rendered->output() === '') {
            throw new RuntimeException('ffmpeg could not write the clip: '.self::lastLine($rendered->errorOutput()));
        }

        return $rendered->output();
    }

    /**
     * The integrated loudness `loudnorm` prints, as JSON, at the end of a
     * measuring pass.
     *
     * @return array{input_i: string}
     */
    private static function measurement(string $log): array
    {
        $start = strrpos($log, '{');
        $end = strrpos($log, '}');
        $values = null;

        if ($start !== false && $end !== false && $end > $start) {
            try {
                $values = json_decode(substr($log, $start, $end - $start + 1), true, 4, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $values = null;
            }
        }

        $loudness = is_array($values) ? ($values['input_i'] ?? null) : null;

        if (! is_string($loudness) || ! is_numeric($loudness)) {
            throw new RuntimeException('ffmpeg measured no loudness in the clip (is it silent?).');
        }

        return ['input_i' => $loudness];
    }

    private static function lastLine(string $log): string
    {
        $lines = array_values(array_filter(array_map(trim(...), explode("\n", $log))));

        return $lines === [] ? 'no output' : $lines[count($lines) - 1];
    }
}
