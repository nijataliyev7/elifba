<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\PersonName;

/**
 * First names the board can say (docs/architecture.md §3 "Voice", ADR 016):
 * a display name is voiced only when its first name is on the built-in name
 * list, `content/names.json`, and its clip has been made, offline, by
 * `php artisan content:voice`, which reads that list and never a row.
 *
 * **The match is exact.** The first name of the display name (normalized
 * when it was saved), as `Language\Support\PersonName::firstName()` reads
 * it, must be one listed word, compared with `Az::lower()`: «Əli M.» is
 * said «Əli», «ƏLİ» too, and so is a name written with its surname or
 * patronymic, either way round («Nihat Əliyev», «Əliyev Nihat», «Nihat
 * Rəşid oğlu» are said «Nihat»), and so is the first word of any name of
 * more words, the rest taken as the surname («Nicat mp», «Ayşən Nur» →
 * «Nicat», «Ayşən»); «Ayşən-Nur» is silent. A name typed without the Azerbaijani letters is
 * found by `Az::plain()` when exactly one listed name is typed so («Lale»
 * is said «Lalə», «Huseyn» «Hüseyn», «ILKIN» «İlkin»); when two are («Roya»
 * and «Röya»), only the exact spelling is said. Nothing else is near:
 * «Ali» is not «Əli» (a is not ə), «Nihad» not «Nihat». The clip says the list's spelling, «Nihat!», in the voice of
 * `voice.json` with `names.style`, under `VoiceClips::hash()` as every clip.
 *
 * **No public file.** The clips live in `content/voice-names/{hash}.mp3`,
 * beside `content/assets` and never through `ContentAssets`: since a name's
 * hash can be computed from the public list and settings, a URL with it
 * would carry the name. A board gets a clip only through its game, by ids
 * (`Play\Support\PlayerVoice`); this class gives a path on disk, never a URL.
 */
final class NameVoices
{
    /**
     * Under the content root, beside `assets`: `storage:link` serves none of it.
     */
    public const string FOLDER = 'voice-names';

    /**
     * @var array<string, int>|null a listed name → its place in the list
     */
    private ?array $listed = null;

    /**
     * @var array<string, string|null>|null `Az::plain()` of a listed name → the
     *                                      name, null when two listed names share it
     */
    private ?array $plain = null;

    public function __construct(
        private readonly ContentRepository $content,
        private readonly VoiceClips $clips,
    ) {}

    /**
     * The list key a display name is said by (`Əli M.` → `əli`, `Nihat
     * Əliyev` → `nihat`, `Nicat mp` → `nicat`), or null when its first name
     * is not one listed word: a hyphenated first name, or a name off the
     * list.
     */
    public function key(string $displayName): ?string
    {
        $name = $this->listedName($displayName);

        return $name === null ? null : Az::lower($name);
    }

    /**
     * The clip of a listed name: `[excited] Nihat!`.
     */
    public function line(string $name): VoiceLineData
    {
        return self::lineIn($this->clips, $name);
    }

    /**
     * The clip of a listed name in a voice (the validator checks a voice
     * it has not loaded through the repository).
     */
    public static function lineIn(VoiceClips $clips, string $name): VoiceLineData
    {
        return $clips->line(VoiceLineData::NAME, "{$name}!", $clips->voice()->nameStyle);
    }

    /**
     * The absolute path of the clip that says a display name's first name,
     * when it has been made (`voice-clips.json`'s `names` lists it and the
     * file is there); null otherwise.
     */
    public function file(string $displayName): ?string
    {
        $name = $this->listedName($displayName);

        if ($name === null) {
            return null;
        }

        $line = $this->line($name);

        if (! isset($this->clips->voice()->names[$line->hash])) {
            return null;
        }

        $path = $this->content->root().'/'.self::path($line->hash);

        return is_file($path) ? $path : null;
    }

    /**
     * The display name with its first name spelled as the list spells it,
     * when it is written otherwise, the rest of the name as it is: «Lale
     * M.» → «Lalə M.», «AYSEL» → «Aysel», «Lale Məmmədova» → «Lalə
     * Məmmədova» (ADR 018); null when it is spelled so already or has no
     * listed first name. For the pages' one-tap fix (ADR 016 decision 10):
     * the board shows the name as it is stored, to children learning to
     * read.
     */
    public function corrected(string $displayName): ?string
    {
        $words = explode(' ', Az::normalize($displayName));
        $first = PersonName::firstName($displayName);

        if ($first === null || ($at = array_search($first, $words, true)) === false) {
            return null;
        }

        $listed = $this->listedName($first);

        if ($listed === null || $listed === $first) {
            return null;
        }

        $words[$at] = $listed;

        return implode(' ', $words);
    }

    /**
     * Whether the board can say a display name: derived from the name and
     * the list on every call, never stored.
     */
    public function has(string $displayName): bool
    {
        return $this->file($displayName) !== null;
    }

    /**
     * The clip of every listed name, by hash, in list order.
     *
     * @return array<string, VoiceLineData>
     */
    public function needed(): array
    {
        $lines = [];

        foreach ($this->content->names()->names as $name) {
            $line = $this->line($name);
            $lines[$line->hash] ??= $line;
        }

        return $lines;
    }

    /**
     * The file of a name clip's hash, relative to the content root.
     */
    public static function path(string $hash): string
    {
        return self::FOLDER.'/'.$hash.'.'.VoiceClips::EXTENSION;
    }

    /**
     * The list's spelling of a display name's first name, or null. Every
     * listed name is its own `Az::title()` and none is listed twice by
     * `Az::lower()` (`ContentValidator`), so the title of one word finds it
     * in any casing, compared as `Az::lower()` would; failing that, the one
     * listed name typed the same without the Azerbaijani letters.
     */
    private function listedName(string $displayName): ?string
    {
        $first = PersonName::firstName($displayName) ?? '';

        if (preg_match('/^\p{L}+$/u', $first) !== 1) {
            return null;
        }

        $this->listed ??= array_flip($this->content->names()->names);
        $name = Az::title($first);

        return isset($this->listed[$name]) ? $name : $this->plainNames()[Az::plain($first)] ?? null;
    }

    /**
     * @return array<string, string|null>
     */
    private function plainNames(): array
    {
        if ($this->plain === null) {
            $this->plain = [];

            foreach ($this->content->names()->names as $name) {
                $key = Az::plain($name);
                $this->plain[$key] = array_key_exists($key, $this->plain) ? null : $name;
            }
        }

        return $this->plain;
    }
}
