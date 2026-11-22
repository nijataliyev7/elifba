<?php

namespace App\Domain\Rooms\Support\Mechanics;

use App\Domain\Content\Data\RoomClueData;
use App\Domain\Content\Data\RoomLevelData;
use App\Domain\Content\Enums\RoomItemKind;
use App\Domain\Content\Support\RoomWords;
use App\Domain\Language\Support\Az;
use App\Domain\Rooms\Data\BuiltRoomRoundData;
use App\Domain\Rooms\Data\RoomAskData;
use App\Domain\Rooms\Data\RoomItemData;
use App\Domain\Rooms\Data\RoomSolutionData;
use App\Domain\Rooms\Enums\RoomAnswerShape;
use App\Domain\Rooms\Enums\RoomLayout;
use InvalidArgumentException;
use Random\Randomizer;

/**
 * Rounds of `anagram`, «Anaqram» (docs/architecture.md §12 "Room games",
 * ADR 023; from ADR 037 its clue and its orders): the letters of a word of
 * `content/rooms/words.json`, shuffled; tap them into a word (`sequence`,
 * `pick` = its letters). Nothing is studied.
 *
 * - The word is drawn from the level's `word_length` among the words an
 *   anagram can be drawn from (`RoomWords::forAnagram()`), none twice in a
 *   game while the level's words last (`RoomWordDraw`).
 * - The tiles are its letters, kind `letter`, each cased by `Az::upper`
 *   (i → İ, ı → I), with ids of their own (`RoomItemIds`).
 * - The solution accepts every correct spelling of the list with those
 *   letters (`RoomWords::accepted()`: «alma» and «amal»), read in the
 *   tiles' capitals, so two tiles of one letter are interchangeable; its
 *   ids are the tiles in the drawn word's order, and its `word` the drawn
 *   word in capitals, which the reveal shows.
 * - The question carries the word's clue as `content/rooms/clues.json`
 *   writes it — whole, or its field alone where the level says so
 *   (`RoomClueData::shown()`, the level's `clue`; ADR 037 as amended
 *   2026-11-22) — (`ask.clue`; null for a word the clues given here lack) and
 *   a bounded list of the tiles' orders (`ask.orders`, `AnagramOrders`):
 *   none reads an accepted spelling, not only the drawn word, no two read
 *   alike, and the first is the order of the question's `items`.
 */
final readonly class AnagramBuilder implements RoomRoundBuilder
{
    /**
     * @param  array<string, string>  $clues  word → its clue (`RoomClueData::$clues`)
     */
    public function __construct(
        private RoomWords $words,
        private array $clues = [],
    ) {}

    public function build(RoomLevelData $level, int $count, Randomizer $random): array
    {
        $length = $level->wordLength;

        if ($length === null || $level->studyMs !== 0) {
            throw new InvalidArgumentException('An anagram level needs a word length and nothing to study.');
        }

        $pool = $this->words->forAnagram($length);

        if ($pool === []) {
            throw new InvalidArgumentException("No word of {$length->min}–{$length->max} letters can be shown out of order.");
        }

        return array_map(
            fn (string $word): BuiltRoomRoundData => $this->round($level, $word, $random),
            RoomWordDraw::draw($pool, $count, $random),
        );
    }

    private function round(RoomLevelData $level, string $word, Randomizer $random): BuiltRoomRoundData
    {
        $ids = new RoomItemIds($random);
        $tiles = array_map(
            static fn (string $letter): RoomItemData => new RoomItemData($ids->next(), RoomItemKind::Letter, Az::upper($letter)),
            mb_str_split($word),
        );
        $accept = array_map(self::capitals(...), $this->words->accepted($word));

        // `$tiles` are still in the word's order: that order is the solution's.
        $solution = new RoomSolutionData(
            RoomAnswerShape::Sequence,
            array_map(static fn (RoomItemData $tile): string => $tile->id, $tiles),
            $accept,
            Az::upper($word),
        );

        $orders = AnagramOrders::draw($tiles, $accept, 0, $random);

        return new BuiltRoomRoundData(
            study: null,
            ask: new RoomAskData(
                RoomAnswerShape::Sequence,
                count($tiles),
                RoomLayout::Row,
                null,
                $orders[0],
                clue: isset($this->clues[$word]) ? RoomClueData::shown($this->clues[$word], $level->clue) : null,
                orders: array_map(static fn (array $order): array => array_map(static fn (RoomItemData $tile): string => $tile->id, $order), $orders),
            ),
            solution: $solution,
            studyMs: 0,
            answerMs: $level->answerMs,
        );
    }

    /**
     * A spelling as the tiles read it: its letters in capitals.
     *
     * @return list<string>
     */
    private static function capitals(string $spelling): array
    {
        return array_map(Az::upper(...), mb_str_split($spelling));
    }
}
