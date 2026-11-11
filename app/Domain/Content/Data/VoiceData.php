<?php

namespace App\Domain\Content\Data;

/**
 * `content/voice.json` with the clip list of `content/voice-clips.json`
 * (docs/architecture.md §3 "Voice", ADR 015). The first is written by hand:
 * the voice, the audio tag of each prompt template (`find.picture` →
 * `playful`), how letters and numbers are said, respellings, the context the
 * provider gets around each phrase, the praise and retry phrases and the
 * credits entry the clips get. The second is written by
 * `php artisan content:voice`: every clip under `content/assets/voice`, by
 * hash, with the text it was made from.
 *
 * @phpstan-type CreditArray array{title: string, author: string, source: string, license: string, changes: string}
 * @phpstan-type VoiceArray array{
 *     version: int,
 *     voice: array{provider: string, model: string, voice_id: string, voice_name: string, language: string, stability: float|int, similarity: float|int},
 *     styles: array<string, string>,
 *     spoken: array{letters: array<string, string>, numbers: array<string, string>, respell: array<string, string>},
 *     context: array{previous: string, next: string},
 *     praise: list<array{text: string, style: string}>,
 *     retry: array{text: string, style: string},
 *     credit: CreditArray,
 *     clips: array<string, string>,
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
     * @param  CreditArray  $credit  `changes` holds `{month}`, the month of generation
     * @param  array<string, string>  $clips  hash → the text the clip was made from
     */
    public function __construct(
        public int $version,
        public VoiceSettingsData $settings,
        public array $styles,
        public array $letters,
        public array $numbers,
        public array $respell,
        public array $praise,
        public VoicePhraseData $retry,
        public array $credit,
        public array $clips,
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
            retry: VoicePhraseData::fromArray($data['retry']),
            credit: $data['credit'],
            clips: $data['clips'],
        );
    }
}
