<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Language\Support\Az;
use App\Domain\Language\Support\Syllabifier;

/**
 * A level from `content/levels.json` (docs/architecture.md §2): which unit,
 * which content of a pack fits it, which mechanics it allows and how many
 * items a round shows. Exactly one filter belongs to each unit: `shapes`
 * for syllables, `syllables` for words, `words` for sentences, none for
 * letters (they come from the alphabet and the class's learned letters).
 */
final readonly class LevelData
{
    /**
     * @param  list<Mechanic>  $mechanics
     * @param  int|null  $items  items a `find` round shows; null when the level has no `find`
     * @param  int|null  $decoys  decoy tiles a build round adds; null when the level has no build mechanic
     * @param  list<string>  $shapes  syllable shapes (`CV`, `CVC`…) for syllable levels
     * @param  RangeData|null  $syllables  syllables per word for word levels
     * @param  RangeData|null  $words  words per sentence for sentence levels
     */
    public function __construct(
        public string $key,
        public TitleData $title,
        public Unit $unit,
        public array $mechanics,
        public ?int $items,
        public ?int $decoys,
        public array $shapes,
        public ?RangeData $syllables,
        public ?RangeData $words,
    ) {}

    /**
     * @param  array{key: string, title: array{az: string, en: string}, unit: string, mechanics: list<string>, items?: int, decoys?: int, shapes?: list<string>, syllables?: array{min: int, max: int}, words?: array{min: int, max: int}}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            key: $data['key'],
            title: TitleData::fromArray($data['title']),
            unit: Unit::from($data['unit']),
            mechanics: array_map(Mechanic::from(...), $data['mechanics']),
            items: $data['items'] ?? null,
            decoys: $data['decoys'] ?? null,
            shapes: $data['shapes'] ?? [],
            syllables: isset($data['syllables']) ? RangeData::fromArray($data['syllables']) : null,
            words: isset($data['words']) ? RangeData::fromArray($data['words']) : null,
        );
    }

    public function allows(Mechanic $mechanic): bool
    {
        return in_array($mechanic, $this->mechanics, true);
    }

    /**
     * The most items a round of this level can show: `items` for `find` and
     * `pop`; for a build mechanic its tiles, at most the longest answer (the
     * letters of the longest shape, or the most syllables or words) plus the
     * decoys. The builders show fewer when a part repeats (`nə-nə`).
     */
    public function mostItems(): int
    {
        $longest = match ($this->unit) {
            Unit::Letter => 1,
            Unit::Syllable => max([0, ...array_map(strlen(...), $this->shapes)]),
            Unit::Word => $this->syllables->max ?? 0,
            Unit::Sentence => $this->words->max ?? 0,
        };
        $most = 0;

        foreach ($this->mechanics as $mechanic) {
            $most = max($most, $mechanic->builds() ? $longest + ($this->decoys ?? 0) : ($this->items ?? 0));
        }

        return $most;
    }

    public function fitsSyllable(string $syllable): bool
    {
        return $this->unit === Unit::Syllable && in_array(Syllabifier::shape($syllable), $this->shapes, true);
    }

    public function fitsWord(WordData $word): bool
    {
        return $this->unit === Unit::Word && $this->syllables?->contains(count($word->syllables)) === true;
    }

    public function fitsSentence(SentenceData $sentence): bool
    {
        return $this->unit === Unit::Sentence && $this->words?->contains(count($sentence->words())) === true;
    }

    /**
     * The distinct items of a pack this level can use, as content keys
     * (`syllable:ba`, `word:alma`, `sentence:fruit:…`), in pack order. With
     * `$letters`, only items made of those lowercase letters; null = all 32.
     * Letter levels take nothing from a pack.
     *
     * @param  list<string>|null  $letters
     * @return list<string>
     */
    public function keysFrom(PackData $pack, ?array $letters = null): array
    {
        $learned = static fn (array $used): bool => $letters === null || array_diff($used, $letters) === [];
        $keys = [];

        if ($this->unit === Unit::Syllable) {
            foreach ($pack->words as $word) {
                foreach ($word->syllables as $syllable) {
                    if ($this->fitsSyllable($syllable) && $learned(mb_str_split(Az::lower($syllable)))) {
                        $keys[] = 'syllable:'.Az::lower($syllable);
                    }
                }
            }
        }

        if ($this->unit === Unit::Word) {
            foreach ($pack->words as $word) {
                if ($this->fitsWord($word) && $learned($word->letters())) {
                    $keys[] = $word->key();
                }
            }
        }

        if ($this->unit === Unit::Sentence) {
            foreach ($pack->sentences as $sentence) {
                if ($this->fitsSentence($sentence) && $learned($sentence->letters())) {
                    $keys[] = $sentence->key();
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
