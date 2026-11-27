<?php

namespace App\Domain\Content\Enums;

/**
 * The rules of an adults' room round (docs/architecture.md §12 "Room
 * games", ADR 023). The rules are code in `Rooms` (a builder in
 * `Rooms\Support\Mechanics`, an answer shape for `RoomJudge`, a view);
 * the enum lives in Content, as `Mechanic` does, because
 * `content/rooms/levels.json` names mechanics and the validator checks
 * them. Separate from `Mechanic`: no value of one is a value of the other.
 *
 * The enum grows with the steps that build each mechanic's builder and
 * its validator rules — B3 `recall_order`; B4 `recall_cells`; B5
 * `next_in_sequence`, `odd_one_out`; B6 `anagram`, `spelling` — so every
 * case of a race has a builder and a level of it is checked in full. D3
 * adds `draughts`, a board game (ADR 034): its rules are
 * `Rooms\Support\Draughts`, its level is a clock, and it builds no rounds.
 */
enum RoomMechanic: string
{
    /**
     * «Ardıcıllığı yadda saxla»: a row of digits, letters, words or shapes
     * is shown, then hidden; tap them in the order shown (`sequence`).
     */
    case RecallOrder = 'recall_order';

    /**
     * «Xanaları yadda saxla»: cells of a grid light up, then go dark; tap
     * the cells that were lit (`set`).
     */
    case RecallCells = 'recall_cells';

    /**
     * «Sıranı davam etdir»: a row of numbers follows a rule; choose what
     * comes next among four (`choice`).
     */
    case NextInSequence = 'next_in_sequence';

    /**
     * «Artıq olanı tap»: one of the numbers or words does not belong with
     * the others; choose it (`choice`).
     */
    case OddOneOut = 'odd_one_out';

    /**
     * «Anaqram»: the letters of a word, shuffled and never in the order of
     * a word they spell; tap them into a word (`sequence`).
     */
    case Anagram = 'anagram';

    /**
     * «Düzgün yazılış»: one word written four ways, the wrong ones by
     * swapping letters for their confusables; choose the right (`choice`).
     */
    case Spelling = 'spelling';

    /**
     * «Şaşki»: Russian draughts on 8 × 8, two seats taking turns, a clock
     * each (D3, ADR 034). A board game: no rounds, no answer.
     */
    case Draughts = 'draughts';

    /**
     * «101»: the local domino game, two to four seats each with seven tiles
     * nobody else sees, hands played to 101 (Y5, ADR 038). A domino game:
     * no rounds, no answer; its level is a turn's time.
     */
    case Domino101 = 'domino_101';

    public function family(): RoomFamily
    {
        return match ($this) {
            self::RecallOrder, self::RecallCells => RoomFamily::Memory,
            self::NextInSequence, self::OddOneOut => RoomFamily::Logic,
            self::Anagram, self::Spelling => RoomFamily::Words,
            self::Draughts => RoomFamily::Board,
            self::Domino101 => RoomFamily::Domino,
        };
    }

    /** A race of rounds or a board game: the family's (ADR 034 decision 1). */
    public function kind(): RoomGameKind
    {
        return $this->family()->kind();
    }

    /**
     * Whether a round shows material to study first (`study_ms` above 0).
     */
    public function studies(): bool
    {
        return $this->family()->studies();
    }

    /**
     * The kinds of item a level of this mechanic may name in `kinds`, as
     * far as their material exists: shapes from B4 (`content/rooms/
     * shapes.json`), short words from B6 (`content/rooms/words.json`). A
     * `recall_cells` level names none: its items are the cells of its
     * grid; nor does a logic level: `next_in_sequence` shows numbers, and
     * `odd_one_out` what its `source` says; nor a word game: its items are
     * what its word makes; nor a board or domino game, which has no items.
     *
     * @return list<RoomItemKind>
     */
    public function kinds(): array
    {
        return match ($this) {
            self::RecallOrder => [RoomItemKind::Digit, RoomItemKind::Letter, RoomItemKind::Word, RoomItemKind::Shape],
            self::RecallCells, self::NextInSequence, self::OddOneOut, self::Anagram, self::Spelling, self::Draughts, self::Domino101 => [],
        };
    }
}
