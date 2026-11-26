<?php

namespace App\Domain\Content\Enums;

/**
 * The families of adults' room games (docs/architecture.md §12 "Room
 * games", "Board games", ADRs 023 and 034): «Yaddaş», «Məntiq və
 * ardıcıllıq», «Söz oyunları» — races of rounds — and from D3 «Taxta
 * oyunları», the board games, and from Y5 «Domino» (ADR 038 decision 1). A family is offered once it has a mechanic
 * `Rooms\Support\RoomPlayable` lists, and it says the kind of its games
 * (`kind()`). Separate from the children's `Mechanic` and `Unit`.
 */
enum RoomFamily: string
{
    /** Something is shown, then hidden: recall it. */
    case Memory = 'memory';

    /** Numbers and words that follow a rule, or one that does not. */
    case Logic = 'logic';

    /** Azerbaijani words: letters to order, spellings to tell apart. */
    case Words = 'words';

    /** Two seats take turns on one board: draughts (D3, ADR 034). */
    case Board = 'board';

    /** Two to four seats play tiles from hands nobody else sees: «101» (Y5, ADR 038). */
    case Domino = 'domino';

    /** A race of rounds, a board game or a domino game (ADR 034 decision 1, ADR 038 decision 1). */
    public function kind(): RoomGameKind
    {
        return match ($this) {
            self::Board => RoomGameKind::Board,
            self::Domino => RoomGameKind::Domino,
            self::Memory, self::Logic, self::Words => RoomGameKind::Race,
        };
    }

    /**
     * Whether a round of this family shows material to study before the
     * question (`room_rounds.study_ms` above 0): memory games only.
     */
    public function studies(): bool
    {
        return $this === self::Memory;
    }
}
