<?php

namespace App\Domain\Content\Data;

use App\Domain\Language\Support\Az;

/**
 * A theme pack: its look and its vocabulary (`content/packs/{key}.json`,
 * docs/architecture.md §3). `version` is bumped on every change; games
 * record it and asset URLs carry it.
 */
final readonly class PackData
{
    /**
     * @param  list<WordData>  $words
     * @param  list<SentenceData>  $sentences
     */
    public function __construct(
        public string $key,
        public int $version,
        public TitleData $title,
        public PackStyleData $style,
        public array $words,
        public array $sentences,
    ) {}

    /**
     * @param  array{key: string, version: int, title: array{az: string, en: string}, style: array{item: string, slot: string, effect: string, backdrop?: string, palette: list<string>, sounds?: array<'hit'|'miss'|'solved', string>}, words: list<array{text: string, image?: string, syllables?: list<string>}>, sentences: list<array{id: string, text: string}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            version: $data['version'],
            title: TitleData::fromArray($data['title']),
            style: PackStyleData::fromArray($data['style']),
            words: array_map(WordData::fromArray(...), $data['words']),
            sentences: array_map(
                static fn (array $sentence): SentenceData => SentenceData::fromArray($sentence, $data['key']),
                $data['sentences'],
            ),
        );
    }

    public function word(string $text): ?WordData
    {
        $lower = Az::lower($text);

        foreach ($this->words as $word) {
            if (Az::lower($word->text) === $lower) {
                return $word;
            }
        }

        return null;
    }

    public function sentence(string $id): ?SentenceData
    {
        foreach ($this->sentences as $sentence) {
            if ($sentence->id === $id) {
                return $sentence;
            }
        }

        return null;
    }
}
