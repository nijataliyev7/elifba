<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Unit;
use App\Domain\Play\Data\GameSetupData;
use LogicException;
use Random\Randomizer;

/**
 * What every round builder shares (docs/architecture.md §2): the deck the
 * targets come from, the item ids and the content as written, before
 * `LetterCase` cases it.
 */
final class RoundParts
{
    /**
     * A target needs another one to alternate with.
     */
    public const int MIN_TARGETS = 2;

    /**
     * Targets for `$count` rounds: shuffled decks of every target, the next
     * deck never starting with the target that ended the last one. Every
     * target comes up equally often (±1) and none twice in a row.
     *
     * @param  list<string>  $targets  at least MIN_TARGETS
     * @return list<string>
     */
    public static function deck(array $targets, int $count, Randomizer $random): array
    {
        $sequence = [];
        $deck = [];

        while (count($sequence) < $count) {
            if ($deck === []) {
                /** @var list<string> $deck */
                $deck = $random->shuffleArray($targets);
                $last = count($deck) - 1;

                if ($sequence !== [] && $deck[0] === $sequence[count($sequence) - 1]) {
                    [$deck[0], $deck[$last]] = [$deck[$last], $deck[0]];
                }
            }

            $sequence[] = (string) array_shift($deck);
        }

        return $sequence;
    }

    /**
     * A builder builds from the setup's own pack (as the teacher plays it).
     *
     * @throws LogicException when the pack is another one
     */
    public static function ensurePack(GameSetupData $setup, PackData $pack): void
    {
        if ($pack->key !== $setup->pack) {
            throw new LogicException("The game is set up for the pack \"{$setup->pack}\", not \"{$pack->key}\".");
        }
    }

    /**
     * "a", "b", "c"… in display order.
     */
    public static function itemId(int $position): string
    {
        return chr(ord('a') + $position);
    }

    /**
     * The text of a content key: `letter:ə` → `ə`, `word:alma` → `alma`.
     */
    public static function value(string $key): string
    {
        return explode(':', $key, 2)[1] ?? $key;
    }

    /**
     * Content as written: letters and syllables lowercase, a word as the
     * pack spells it (a proper noun keeps its capital).
     */
    public static function written(string $key, PackData $pack): string
    {
        $value = self::value($key);

        return str_starts_with($key, Unit::Word->value.':') ? ($pack->word($value)->text ?? $value) : $value;
    }
}
