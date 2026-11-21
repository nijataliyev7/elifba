<?php

namespace App\Domain\Content\Data;

/**
 * The anagram's clues, `content/rooms/clues.json` (docs/architecture.md §12
 * "Room content", ADR 037 decisions 1–2): one short Azerbaijani line per
 * word of the adults' word list (`words.json`'s `words`, never its
 * `also_correct`), naming the word's field and one trait without the word
 * — «Yay meyvəsi, bostanda yetişir» for «qarpız». Our own text under MIT,
 * keyed by the word in the list's order and checked by `ContentValidator`.
 *
 * A clue is content: shown as written, in Azerbaijani in every locale.
 *
 * @phpstan-type RoomClueArray array{version: int, clues: array<string, string>}
 */
final readonly class RoomClueData
{
    /**
     * @param  int  $version  bumped on every change, as every content file's
     * @param  array<string, string>  $clues  word → its clue
     */
    public function __construct(
        public int $version,
        public array $clues,
    ) {}

    /**
     * @param  RoomClueArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['version'], $data['clues']);
    }

    /**
     * The word's clue, or null for a word the file has none for.
     */
    public function of(string $word): ?string
    {
        return $this->clues[$word] ?? null;
    }
}
