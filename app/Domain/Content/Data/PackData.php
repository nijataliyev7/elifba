<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\Unit;
use App\Domain\Language\Support\Az;

/**
 * A theme pack: its look and its vocabulary (`content/packs/{key}.json`,
 * docs/architecture.md §3). `version` is bumped on every change; games
 * record it and asset URLs carry it. `units` (R5, ADR 012) are the units
 * it has content for: a level is offered with the pack only when the pack
 * lists the level's unit. A pack without the reading units has no
 * sentences. `with` is the title as a game's card names it (2026-11-13,
 * docs/frontend.md rule 13): in Azerbaijani the title in the instrumental
 * case, written out («Qatarla»), in English the phrase after a verb ("with
 * the train").
 */
final readonly class PackData
{
    /**
     * @param  list<Unit>  $units  in `Unit` order
     * @param  list<WordData>  $words
     * @param  list<SentenceData>  $sentences
     */
    public function __construct(
        public string $key,
        public int $version,
        public TitleData $title,
        public TitleData $with,
        public array $units,
        public PackStyleData $style,
        public array $words,
        public array $sentences,
    ) {}

    /**
     * @param  array{key: string, version: int, title: array{az: string, en: string}, with: array{az: string, en: string}, units: list<string>, style: array{item: string, slot: string, effect: string, cover: string, backdrop?: string, palette: list<string>, sounds?: array<'hit'|'miss'|'solved', string>, figures?: list<string>}, words: list<array{text: string, image?: string, syllables?: list<string>}>, sentences?: list<array{id: string, text: string}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            version: $data['version'],
            title: TitleData::fromArray($data['title']),
            with: TitleData::fromArray($data['with']),
            units: array_map(Unit::from(...), $data['units']),
            style: PackStyleData::fromArray($data['style']),
            words: array_map(WordData::fromArray(...), $data['words']),
            sentences: array_map(
                static fn (array $sentence): SentenceData => SentenceData::fromArray($sentence, $data['key']),
                $data['sentences'] ?? [],
            ),
        );
    }

    /**
     * The same pack with other words: a teacher's pack (`TeacherPacks`).
     *
     * @param  list<WordData>  $words
     */
    public function withWords(array $words): self
    {
        return new self($this->key, $this->version, $this->title, $this->with, $this->units, $this->style, $words, $this->sentences);
    }

    /**
     * Whether the pack has content for this unit (`units`).
     */
    public function lists(Unit $unit): bool
    {
        return in_array($unit, $this->units, true);
    }

    /**
     * The words that have a picture, in pack order: what a picture level
     * names and a `count` round counts. A teacher's word has none.
     *
     * @return list<WordData>
     */
    public function pictured(): array
    {
        return array_values(array_filter($this->words, static fn (WordData $word): bool => $word->image !== null));
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
