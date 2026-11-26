<?php

namespace App\Domain\Content\Data;

use App\Domain\Content\Enums\RoomClueForm;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Enums\RoomSequenceRule;
use App\Domain\Content\Enums\RoomSource;

/**
 * A difficulty of a room mechanic, from `content/rooms/levels.json`
 * (docs/architecture.md §12 "Room games", "Room content", ADR 023): one
 * per mechanic and difficulty 1–4 («Asan», «Orta», «Çətin», «Çox çətin»),
 * so the pair is the level's key. It holds the times every mechanic has
 * and the numbers of its own mechanic; the fields of another mechanic are
 * empty. `ContentValidator` checks them all.
 *
 * - `recall_order`: `kinds` (one per round) and `length` (items in a row).
 * - `recall_cells`: `grid` (`{cols, rows}`) and `lit` (how many of its
 *   cells light up, fewer than the cells).
 * - `next_in_sequence` (B5): `rules` (one per round), `terms` (how many a
 *   row shows) and `range` (every number a round shows).
 * - `odd_one_out` (B5): `source` (numbers or words) and `options` (how
 *   many to choose from, 4 or 5).
 * - `anagram` (B6): `word_length` (`{min, max}` letters of the word);
 *   from ADR 037 as amended 2026-11-22 `clue`, how much of the word's
 *   clue the question shows (`RoomClueForm`: `full`, or its `field` alone).
 * - `spelling` (B6): `word_length` and `swaps` (1 or 2: how many letters
 *   at least tell two of the four spellings apart, `Content\Support\RoomWords`).
 * - `draughts` (D3, ADR 034 decision 6), a board game: its level is its
 *   clock — `clock_ms`, each side's time, and `increment_ms`, what a side
 *   gains with each move — and nothing else; it has no rounds, so its
 *   `study_ms` and `answer_ms` are 0 and not in the file. Its difficulties
 *   are 1 to 3, a higher number a faster game.
 * - `domino_101` (Y5, ADR 038 decision 22), a domino game: its level is a
 *   turn's time — `turn_ms` — and nothing else; no rounds, no clock a side.
 *   Its difficulties are 1 to 3, a higher number a shorter turn.
 *
 * @phpstan-type RoomLevelArray array{mechanic: string, difficulty: int, study_ms?: int, answer_ms?: int, clock_ms?: int, increment_ms?: int, turn_ms?: int, kinds?: list<string>, length?: array{min: int, max: int}, grid?: array{cols: int, rows: int}, lit?: array{min: int, max: int}, rules?: list<string>, terms?: int, range?: array{min: int, max: int}, source?: string, options?: int, word_length?: array{min: int, max: int}, swaps?: int, clue?: string}
 */
final readonly class RoomLevelData
{
    /**
     * @param  int  $difficulty  1–4
     * @param  int  $studyMs  how long the material is shown; 0 unless the mechanic `studies()`
     * @param  int  $answerMs  the time to answer; 0 for a board game
     * @param  list<RoomItemKind>  $kinds  what a `recall_order` round may show
     * @param  RangeData|null  $length  items in a `recall_order` row
     * @param  GridData|null  $grid  a `recall_cells` round's grid
     * @param  RangeData|null  $lit  how many cells of it a `recall_cells` round lights
     * @param  list<RoomSequenceRule>  $rules  what a `next_in_sequence` row may follow
     * @param  int|null  $terms  how many terms a `next_in_sequence` row shows
     * @param  RangeData|null  $range  the numbers a `next_in_sequence` round shows
     * @param  RoomSource|null  $source  what an `odd_one_out` round's options are
     * @param  int|null  $options  how many options an `odd_one_out` round offers
     * @param  RangeData|null  $wordLength  the letters of a word game's word
     * @param  int|null  $swaps  a `spelling` level's: 1 or 2
     * @param  int|null  $clockMs  a board game's: each side's time
     * @param  int|null  $incrementMs  a board game's: what a side gains with each move
     * @param  RoomClueForm|null  $clue  an `anagram` level's: the clue whole or its field alone; null shows it whole
     * @param  int|null  $turnMs  a domino game's: a turn's time (ADR 038 decision 22)
     */
    public function __construct(
        public RoomMechanic $mechanic,
        public int $difficulty,
        public int $studyMs,
        public int $answerMs,
        public array $kinds = [],
        public ?RangeData $length = null,
        public ?GridData $grid = null,
        public ?RangeData $lit = null,
        public array $rules = [],
        public ?int $terms = null,
        public ?RangeData $range = null,
        public ?RoomSource $source = null,
        public ?int $options = null,
        public ?RangeData $wordLength = null,
        public ?int $swaps = null,
        public ?int $clockMs = null,
        public ?int $incrementMs = null,
        public ?RoomClueForm $clue = null,
        public ?int $turnMs = null,
    ) {}

    /**
     * @param  RoomLevelArray  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            mechanic: RoomMechanic::from($data['mechanic']),
            difficulty: $data['difficulty'],
            studyMs: $data['study_ms'] ?? 0,
            answerMs: $data['answer_ms'] ?? 0,
            kinds: array_map(RoomItemKind::from(...), $data['kinds'] ?? []),
            length: isset($data['length']) ? RangeData::fromArray($data['length']) : null,
            grid: isset($data['grid']) ? GridData::fromArray($data['grid']) : null,
            lit: isset($data['lit']) ? RangeData::fromArray($data['lit']) : null,
            rules: array_map(RoomSequenceRule::from(...), $data['rules'] ?? []),
            terms: $data['terms'] ?? null,
            range: isset($data['range']) ? RangeData::fromArray($data['range']) : null,
            source: isset($data['source']) ? RoomSource::from($data['source']) : null,
            options: $data['options'] ?? null,
            wordLength: isset($data['word_length']) ? RangeData::fromArray($data['word_length']) : null,
            swaps: $data['swaps'] ?? null,
            clockMs: $data['clock_ms'] ?? null,
            incrementMs: $data['increment_ms'] ?? null,
            clue: isset($data['clue']) ? RoomClueForm::from($data['clue']) : null,
            turnMs: $data['turn_ms'] ?? null,
        );
    }
}
