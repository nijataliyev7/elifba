<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\RoomClueForm;

/**
 * The anagram's clues, `content/rooms/clues.json` (docs/architecture.md §12
 * "Room content", ADR 037 decisions 1–2): one short Azerbaijani line per
 * word of the adults' word list (`words.json`'s `words`, never its
 * `also_correct`), naming the word's field and, after the first comma, one
 * trait, without the word — «Yay meyvəsi, bostanda yetişir» for «qarpız».
 * Our own text under MIT, keyed by the word in the list's order and checked
 * by `ContentValidator`. A level shows it whole or its field alone
 * (`RoomClueForm`, `shown()`; ADR 037 as amended 2026-11-22).
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

    /**
     * A clue's field: what it says before its first comma — «Yay meyvəsi»
     * of «Yay meyvəsi, bostanda yetişir». A clue without a comma is all
     * field.
     */
    public static function field(string $clue): string
    {
        $comma = mb_strpos($clue, ',');

        return $comma === false ? $clue : rtrim(mb_substr($clue, 0, $comma));
    }

    /**
     * What a level shows of a clue: the whole of it, or its field alone
     * (`RoomClueForm`); a level that names no form shows it whole.
     */
    public static function shown(string $clue, ?RoomClueForm $form): string
    {
        return $form === RoomClueForm::Field ? self::field($clue) : $clue;
    }
}
