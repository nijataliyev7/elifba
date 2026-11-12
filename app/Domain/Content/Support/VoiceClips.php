<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Data\VoiceData;
use App\Domain\Content\Data\VoiceLineData;
use App\Domain\Content\Data\VoicePhraseData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;

/**
 * The board's voice (docs/architecture.md §3 "Voice", ADR 015): what each
 * prompt says aloud, the hash that names its clip and the clip's URL.
 *
 * A clip says what the adult would read: the prompt without « », its first
 * letter a capital, `{x}` said as an adult says it — a letter as a first
 * grade says its sound (`spoken.letters`: `bı`, `rı`, `a`), a number by its
 * Azerbaijani name (`beş`), a syllable or a word as written. The provider is given that phrase (or its respelling) after the
 * template's audio tag: `[playful] Dovşan hansıdır?`. The hash of that text
 * with the voice, the model, the language and the settings names the file
 * (`voice/{hash}.mp3`), with the context the provider gets around it, so
 * changing any of them makes a new file and nothing already made is paid
 * for again.
 *
 * `needed()` lists every clip built-in content can ask for; the round
 * builders ask through `Play\Support\PromptVoice`, which never asks for a
 * teacher word. No row from the database reaches this class. A first name
 * of the built-in list (ADR 016) is a line made here too (`line()`, through
 * `NameVoices`), but it is listed apart (`VoiceData::$names`), its file
 * lives outside `content/assets` and `url()` never gives it one.
 */
final readonly class VoiceClips
{
    /**
     * Under `content/assets`.
     */
    public const string FOLDER = 'voice';

    public const string EXTENSION = 'mp3';

    /**
     * Hex characters of the hash in a file name.
     */
    public const int HASH_LENGTH = 16;

    public function __construct(private VoiceData $voice) {}

    public function voice(): VoiceData
    {
        return $this->voice;
    }

    /**
     * The key of a template in `voice.json`'s `styles`: `find.picture`.
     */
    public static function group(Mechanic $mechanic, Unit $unit): string
    {
        return "{$mechanic->value}.{$unit->value}";
    }

    /**
     * The clip of a prompt: the template alone when it takes no text, else
     * filled with what `$target` (a content key: `letter:ə`, `syllable:ba`,
     * `word:dovşan`, `number:5`) is said as, the word as `$pack` writes it.
     * Null when the template has no style or the target cannot be said (a
     * number without a name).
     */
    public function prompt(PromptTemplateData $template, ?string $target, ?PackData $pack = null): ?VoiceLineData
    {
        $group = self::group($template->mechanic, $template->unit);
        $style = $this->voice->styles[$group] ?? null;

        if ($style === null) {
            return null;
        }

        $item = null;

        if ($template->takesText()) {
            $item = $target === null ? null : $this->spoken($target, $pack);

            if ($item === null) {
                return null;
            }
        }

        $text = str_replace(['«', '»'], '', $item === null ? $template->text : $template->fill($item));

        return $this->line($group, self::capitalized($text), $style);
    }

    /**
     * @return list<VoiceLineData>
     */
    public function praise(): array
    {
        return array_map(fn (VoicePhraseData $phrase): VoiceLineData => $this->line(VoiceLineData::PRAISE, $phrase->text, $phrase->style), $this->voice->praise);
    }

    /**
     * @return list<VoiceLineData>
     */
    public function retry(): array
    {
        return array_map(fn (VoicePhraseData $phrase): VoiceLineData => $this->line(VoiceLineData::RETRY, $phrase->text, $phrase->style), $this->voice->retry);
    }

    /**
     * «Salam!», said before a home game's child's name (ADR 016 decision 7).
     */
    public function greeting(): VoiceLineData
    {
        return $this->line(VoiceLineData::GREETING, $this->voice->greeting->text, $this->voice->greeting->style);
    }

    /**
     * «Əla oynadın!», at the end of a game one player played.
     */
    public function finish(): VoiceLineData
    {
        return $this->line(VoiceLineData::FINISH, $this->voice->finish->text, $this->voice->finish->style);
    }

    /**
     * «Əla oynadınız!», at the end of a game the class played.
     */
    public function finishClass(): VoiceLineData
    {
        return $this->line(VoiceLineData::FINISH_CLASS, $this->voice->finishClass->text, $this->voice->finishClass->style);
    }

    /**
     * The clip's URL when it has been made (`voice-clips.json` lists it),
     * versioned like every content URL; null otherwise, and the board is
     * silent for it. Never for a name: its hash can be computed from the
     * public list, so a URL with it would carry the name (ADR 016).
     */
    public function url(VoiceLineData $line): ?string
    {
        if ($line->isName()) {
            return null;
        }

        return isset($this->voice->clips[$line->hash]) ? ContentAssets::url(self::path($line->hash), $this->voice->version) : null;
    }

    /**
     * The file of a hash, relative to `content/assets`.
     */
    public static function path(string $hash): string
    {
        return self::FOLDER.'/'.$hash.'.'.self::EXTENSION;
    }

    /**
     * The hash that names a clip of `$text` in this voice.
     */
    public function hash(string $text): string
    {
        $settings = $this->voice->settings;
        $key = json_encode([
            $settings->provider,
            $settings->model,
            $settings->voiceId,
            $settings->language,
            $settings->stability,
            $settings->similarity,
            $settings->previousText,
            $settings->nextText,
            $text,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        return substr(hash('sha256', $key), 0, self::HASH_LENGTH);
    }

    /**
     * Every clip built-in content can ask for under `content/assets/voice`,
     * by hash, in the order of `prompts.json`, then praise, retry, the
     * greeting and the two end phrases: each template with every item a
     * level of its unit and mechanic gives in any pack that lists the unit,
     * with all 32 letters learned (learned letters and hidden words only
     * ever take items away). `build_syllable` builds syllables of two or
     * more letters (`BuildSyllableRoundBuilder`); a `count` round counts the
     * pictured words of a pack that lists `number`. The names of the list
     * are `NameVoices::needed()`.
     *
     * @return array<string, VoiceLineData>
     */
    public function needed(ContentRepository $content): array
    {
        $lines = [];
        $add = static function (?VoiceLineData $line) use (&$lines): void {
            if ($line !== null) {
                $lines[$line->hash] ??= $line;
            }
        };

        foreach ($content->prompts() as $template) {
            if (! $template->takesText()) {
                $add($this->prompt($template, null));

                continue;
            }

            foreach ($this->targets($content, $template) as [$target, $pack]) {
                $add($this->prompt($template, $target, $pack));
            }
        }

        foreach ([...$this->praise(), ...$this->retry(), $this->greeting(), $this->finish(), $this->finishClass()] as $line) {
            $add($line);
        }

        return $lines;
    }

    /**
     * What a content key is said as; null for a number without a name.
     */
    private function spoken(string $target, ?PackData $pack): ?string
    {
        [$unit, $value] = explode(':', $target, 2) + [1 => ''];

        return match (Unit::tryFrom($unit)) {
            Unit::Letter => $this->voice->letters[$value] ?? Alphabet::name($value),
            Unit::Number => $this->voice->numbers[(int) $value] ?? null,
            Unit::Word, Unit::Picture => $pack?->word($value)->text ?? $value,
            default => $value,
        };
    }

    /**
     * A clip of `$phrase` in this voice: the tag, then the phrase or its
     * respelling, hashed.
     */
    public function line(string $group, string $phrase, string $style): VoiceLineData
    {
        $text = "[{$style}] ".($this->voice->respell[$phrase] ?? $phrase);

        return new VoiceLineData($group, $phrase, $text, $this->hash($text));
    }

    /**
     * The targets a template can name, each with the pack it comes from.
     *
     * @return list<array{string, PackData|null}>
     */
    private function targets(ContentRepository $content, PromptTemplateData $template): array
    {
        $levels = array_filter(
            $content->levels(),
            static fn (LevelData $level): bool => $level->unit === $template->unit && $level->allows($template->mechanic),
        );

        if ($levels === []) {
            return [];
        }

        if ($template->unit === Unit::Letter) {
            return array_map(static fn (string $letter): array => ["letter:{$letter}", null], Alphabet::letters());
        }

        $targets = [];

        foreach ($content->packs() as $pack) {
            if (! $pack->lists($template->unit)) {
                continue;
            }

            if ($template->mechanic === Mechanic::Count) {
                foreach ($pack->pictured() as $word) {
                    $targets[] = [$word->key(), $pack];
                }

                continue;
            }

            foreach ($levels as $level) {
                foreach ($level->keysFrom($pack) as $key) {
                    if ($template->mechanic !== Mechanic::BuildSyllable || mb_strlen(explode(':', $key, 2)[1] ?? '') >= 2) {
                        $targets[] = [$key, $pack];
                    }
                }
            }
        }

        return $targets;
    }

    private static function capitalized(string $text): string
    {
        return Az::upper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
