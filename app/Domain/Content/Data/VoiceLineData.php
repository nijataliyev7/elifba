<?php

namespace App\Domain\Content\Data;

/**
 * One clip the voice says (docs/architecture.md §3 "Voice"): the phrase as
 * the adult would read it, the text the provider is given for it (its audio
 * tag in front, a respelling where `voice.json` has one) and the hash of
 * that text with the voice, which names the file. `group` says where it
 * comes from: a template (`find.picture`), `praise` or `retry`.
 */
final readonly class VoiceLineData
{
    public const string PRAISE = 'praise';

    public const string RETRY = 'retry';

    public function __construct(
        public string $group,
        public string $phrase,
        public string $text,
        public string $hash,
    ) {}
}
