<?php

namespace App\Domain\Rooms\Support;

use App\Domain\Content\Enums\RoomMechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Rooms\Support\Mechanics\AnagramBuilder;
use App\Domain\Rooms\Support\Mechanics\NextInSequenceBuilder;
use App\Domain\Rooms\Support\Mechanics\OddOneOutBuilder;
use App\Domain\Rooms\Support\Mechanics\RecallCellsBuilder;
use App\Domain\Rooms\Support\Mechanics\RecallOrderBuilder;
use App\Domain\Rooms\Support\Mechanics\RoomRoundBuilder;
use App\Domain\Rooms\Support\Mechanics\SpellingBuilder;
use InvalidArgumentException;

/**
 * The room mechanics a room can play (docs/architecture.md §12 "Room
 * games", ADR 023 decision 2): those whose view the room page draws. The
 * game's setup offers, and `RoomGameSetupData::rules()` accepts, only a
 * level of a mechanic listed here, so content may run ahead of the pages.
 * Every mechanic listed has its four levels (`RoomPlayableTest`).
 *
 * B3 lists `recall_order`, whose view B3 builds with the room page; B4
 * `recall_cells`, with its view; B5 `next_in_sequence` and `odd_one_out`,
 * with theirs; B6 `anagram` and `spelling`, with theirs (part 2 designed
 * them: tiles under the word's places, four spellings in one column).
 * D3 lists `draughts`, a board game (ADR 034), whose rules are
 * `Rooms\Support\Draughts` and which builds no rounds; D4 draws it.
 * Y5 builds `domino_101` (ADR 038), a domino game, which builds no rounds
 * either; Y6 draws it and lists it here (ADR 038 decision 73).
 */
final class RoomPlayable
{
    /**
     * @return list<RoomMechanic>
     */
    public static function mechanics(): array
    {
        return [
            RoomMechanic::RecallOrder, RoomMechanic::RecallCells, RoomMechanic::NextInSequence, RoomMechanic::OddOneOut, RoomMechanic::Anagram, RoomMechanic::Spelling, RoomMechanic::Draughts, RoomMechanic::Domino101,
        ];
    }

    public static function plays(RoomMechanic $mechanic): bool
    {
        return in_array($mechanic, self::mechanics(), true);
    }

    /**
     * The round builder of a race's mechanic; every race has one, and a
     * board game builds no rounds (`RaceDriver::start()` alone asks).
     */
    public static function builder(RoomMechanic $mechanic): RoomRoundBuilder
    {
        return match ($mechanic) {
            RoomMechanic::RecallOrder => new RecallOrderBuilder(app(ContentRepository::class)->roomShapes(), self::words()->forRow()),
            RoomMechanic::RecallCells => new RecallCellsBuilder,
            RoomMechanic::NextInSequence => new NextInSequenceBuilder,
            RoomMechanic::OddOneOut => new OddOneOutBuilder(app(ContentRepository::class)->roomCategories()),
            RoomMechanic::Anagram => new AnagramBuilder(self::words(), app(ContentRepository::class)->roomClues()->clues),
            RoomMechanic::Spelling => new SpellingBuilder(self::words()),
            default => throw new InvalidArgumentException("{$mechanic->value} builds no rounds."),
        };
    }

    /**
     * The adults' word list as the games use it: a first name of the name
     * list is never a wrong spelling either.
     */
    private static function words(): RoomWords
    {
        $content = app(ContentRepository::class);

        return RoomWords::of($content->roomWords(), $content->names()->names);
    }
}
