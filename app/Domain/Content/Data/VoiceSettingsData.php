<?php

namespace App\Domain\Content\Data;

/**
 * The synthetic voice every clip is made in (`content/voice.json`,
 * docs/architecture.md §3 "Voice", ADR 015): the provider, its model and
 * voice, the language and the two settings the owner chose by ear, and the
 * Azerbaijani context the provider is given around every phrase and never
 * says (`previous_text`, `next_text`): without it a short phrase drifted
 * towards Turkish. All of it is part of a clip's hash, so another voice,
 * setting or context makes new files.
 */
final readonly class VoiceSettingsData
{
    public function __construct(
        public string $provider,
        public string $model,
        public string $voiceId,
        public string $voiceName,
        public string $language,
        public float $stability,
        public float $similarity,
        public string $previousText = '',
        public string $nextText = '',
    ) {}

    /**
     * @param  array{provider: string, model: string, voice_id: string, voice_name: string, language: string, stability: float|int, similarity: float|int}  $data
     * @param  array{previous: string, next: string}|null  $context
     */
    public static function fromArray(array $data, ?array $context = null): self
    {
        return new self(
            provider: $data['provider'],
            model: $data['model'],
            voiceId: $data['voice_id'],
            voiceName: $data['voice_name'],
            language: $data['language'],
            stability: (float) $data['stability'],
            similarity: (float) $data['similarity'],
            previousText: $context['previous'] ?? '',
            nextText: $context['next'] ?? '',
        );
    }
}
