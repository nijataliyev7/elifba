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
     * The numbers nearest `$target` among `$pool` (number content keys),
     * `$count` of them: ±1 first, then ±2…, in random order within a
     * distance. A number's "confusables" (R5): its decoys in `find` and
     * `pop`, and N's neighbours in a `count` round.
     *
     * @param  list<string>  $pool  `number:{n}` keys
     * @return list<string>
     */
    public static function nearest(string $target, array $pool, int $count, Randomizer $random): array
    {
        $n = (int) self::value($target);
        /** @var array<int, list<string>> $byDistance */
        $byDistance = [];

        foreach ($pool as $key) {
            if ($key !== $target) {
                $byDistance[abs((int) self::value($key) - $n)][] = $key;
            }
        }

        ksort($byDistance);
        $nearest = [];

        foreach ($byDistance as $keys) {
            /** @var list<string> $shuffled */
            $shuffled = $random->shuffleArray($keys);
            $nearest = [...$nearest, ...$shuffled];
        }

        return array_slice($nearest, 0, $count);
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
