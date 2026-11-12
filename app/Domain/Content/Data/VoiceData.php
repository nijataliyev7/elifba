<?php

namespace App\Domain\Content\Data;

/**
 * `content/voice.json` with the clip list of `content/voice-clips.json`
 * (docs/architecture.md §3 "Voice", ADR 015, ADR 016). The first is written
 * by hand: the voice, the audio tag of each prompt template
 * (`find.picture` → `playful`), how letters and numbers are said,
 * respellings, the context the provider gets around each phrase, the
 * praise and retry phrases, the greeting and the two end phrases, the tag
 * and credits entry of the name clips and the credits entry the other
 * clips get. The second is written by `php artisan content:voice`: every
 * clip under `content/assets/voice` (`clips`) and every name clip under
 * `content/voice-names` (`names`), by hash, with the text it was made from.
 *
 * @phpstan-type CreditArray array{title: string, author: string, source: string, license: string, changes: string}
 * @phpstan-type PhraseArray array{text: string, style: string}
 * @phpstan-type VoiceArray array{
 *     version: int,
 *     voice: array{provider: string, model: string, voice_id: string, voice_name: string, language: string, stability: float|int, similarity: float|int},
 *     styles: array<string, string>,
 *     spoken: array{letters: array<string, string>, numbers: array<string, string>, respell: array<string, string>},
 *     context: array{previous: string, next: string},
 *     praise: list<PhraseArray>,
 *     retry: list<PhraseArray>,
 *     greeting: PhraseArray,
 *     finish: PhraseArray,
 *     finish_class: PhraseArray,
 *     names: array{style: string, credit: CreditArray},
 *     credit: CreditArray,
 *     clips: array<string, string>,
 *     name_clips: array<string, string>,
 * }
 */
final readonly class VoiceData
{
    /**
     * @param  array<string, string>  $styles  `{mechanic}.{unit}` → audio tag, without brackets
     * @param  array<string, string>  $letters  a lowercase letter → how the voice says it (`r` → `rı`)
     * @param  array<int, string>  $numbers  a number → its Azerbaijani name
     * @param  array<string, string>  $respell  a phrase → the text the voice is given for it instead
     * @param  list<VoicePhraseData>  $praise
     * @param  list<VoicePhraseData>  $retry
     * @param  CreditArray  $credit  `changes` holds `{month}`, the month of generation
     * @param  array<string, string>  $clips  hash → the text the clip was made from
     * @param  VoicePhraseData  $greeting  «Salam!» before a home game's child's name (ADR 016)
     * @param  VoicePhraseData  $finish  «Əla oynadın!» at the end of a game, before one player's name
     * @param  VoicePhraseData  $finishClass  «Əla oynadınız!» at the end of a game the class played
     * @param  string  $nameStyle  the audio tag of every name clip (`excited` → `[excited] Nihat!`)
     * @param  CreditArray  $nameCredit  the credits entry of `content/voice-names/*`
     * @param  array<string, string>  $names  hash → the text a name clip was made from
     */
    public function __construct(
        public int $version,
        public VoiceSettingsData $settings,
        public array $styles,
        public array $letters,
        public array $numbers,
        public array $respell,
        public array $praise,
        public array $retry,
        public array $credit,
        public array $clips,
        public VoicePhraseData $greeting,
        public VoicePhraseData $finish,
        public VoicePhraseData $finishClass,
        public string $nameStyle,
        public array $nameCredit,
        public array $names,
    ) {}

    /**
     * @param  VoiceArray  $data
     */
    public static function fromArray(array $data): self
    {
        $numbers = [];

        foreach ($data['spoken']['numbers'] as $number => $name) {
            $numbers[(int) $number] = $name;
        }

        return new self(
            version: $data['version'],
            settings: VoiceSettingsData::fromArray($data['voice'], $data['context']),
            styles: $data['styles'],
            letters: $data['spoken']['letters'],
            numbers: $numbers,
            respell: $data['spoken']['respell'],
            praise: array_map(VoicePhraseData::fromArray(...), $data['praise']),
            retry: array_map(VoicePhraseData::fromArray(...), $data['retry']),
            credit: $data['credit'],
            clips: $data['clips'],
            greeting: VoicePhraseData::fromArray($data['greeting']),
            finish: VoicePhraseData::fromArray($data['finish']),
            finishClass: VoicePhraseData::fromArray($data['finish_class']),
            nameStyle: $data['names']['style'],
            nameCredit: $data['names']['credit'],
            names: $data['name_clips'],
        );
    }
}
