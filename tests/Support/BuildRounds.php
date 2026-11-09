<?php

namespace Tests\Support;

use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Support\Playable;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Rounds of the build mechanics for unit tests, built from the real
 * built-in content through `Playable::builder()` with a fixed seed, and the views of
 * a round the tests compare.
 */
final class BuildRounds
{
    /**
     * @param  list<string>|null  $learned  null = all 32
     * @return list<RoundData>
     */
    public static function build(Mechanic $mechanic, string $level, string $pack, ?array $learned = null, int $seed = 7, int $rounds = 10, LetterCase $case = LetterCase::Lower): array
    {
        return Playable::builder($mechanic)->build(
            new GameSetupData($mechanic, $pack, $level, $rounds, $case),
            app(ContentRepository::class)->pack($pack),
            $learned ?? Alphabet::letters(),
            new Randomizer(new Mt19937($seed)),
        );
    }

    /**
     * The rounds without their ids, which are new on every build.
     *
     * @param  list<RoundData>  $rounds
     * @return list<array<string, mixed>>
     */
    public static function payloads(array $rounds): array
    {
        return array_map(static fn (RoundData $round): array => $round->payload(), $rounds);
    }

    /**
     * What each round asks for: its answer, the tile texts in solution order.
     *
     * @param  list<RoundData>  $rounds
     * @return list<string>
     */
    public static function targets(array $rounds): array
    {
        return array_map(static fn (RoundData $round): string => implode(' ', self::answer($round)), $rounds);
    }

    /**
     * The tile texts in solution order (a reused tile comes again).
     *
     * @return list<string>
     */
    public static function answer(RoundData $round): array
    {
        return array_map(static fn (string $id): string => $round->item($id)->text ?? '', $round->solution->item_ids);
    }

    /**
     * The tile texts in display order.
     *
     * @return list<string>
     */
    public static function texts(RoundData $round): array
    {
        return array_map(static fn (RoundItemData $item): string => $item->text, $round->items);
    }

    /**
     * The tiles that are not in the solution.
     *
     * @return list<RoundItemData>
     */
    public static function decoys(RoundData $round): array
    {
        return array_values(array_filter(
            $round->items,
            static fn (RoundItemData $item): bool => ! in_array($item->id, $round->solution->item_ids, true),
        ));
    }

    /**
     * Whether the answer's tiles (two or more, in order of first use) stand
     * side by side in that order: the answer already built on the board.
     */
    public static function standsBuilt(RoundData $round): bool
    {
        $firstUse = array_values(array_unique($round->solution->item_ids));
        $ids = $round->itemIds();
        $start = array_search($firstUse[0], $ids, true);

        return count($firstUse) >= 2 && $start !== false && array_slice($ids, $start, count($firstUse)) === $firstUse;
    }

    /**
     * The lowercase letters of a content key's text.
     *
     * @return list<string>
     */
    public static function letters(string $contentKey): array
    {
        $parts = explode(':', $contentKey);

        return array_values(array_unique(mb_str_split(Az::lower((string) end($parts)))));
    }
}
