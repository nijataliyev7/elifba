<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Data\SolutionData;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Playable;
use Illuminate\Support\Str;
use LogicException;
use Random\Randomizer;

/**
 * The flow every build mechanic shares (docs/architecture.md §2, "The
 * build builders"): a target from the deck, its answer tiles in order and
 * the level's decoy tiles; one tile per text after casing, so a part that
 * comes again reuses its tile and the tile's id repeats in the solution;
 * the tiles shuffled until the answer does not stand built on the board.
 *
 * Each mechanic says which targets it can build, what their tiles and
 * decoys are, and what the prompt shows. Tiles are content as written;
 * `LetterCase` cases them here, never on the board.
 *
 * @phpstan-type Tile array{key: string, text: string}
 */
abstract readonly class BuildRoundBuilder implements RoundBuilder
{
    public function __construct(protected ContentRepository $content) {}

    abstract protected function mechanic(): Mechanic;

    /**
     * The `games.setup.errors.*` reason for too few targets.
     */
    abstract protected function tooFew(): string;

    /**
     * The content keys of the level this mechanic can build, before the
     * decoy check.
     *
     * @param  list<string>  $learned
     * @return list<string>
     */
    abstract protected function candidates(PackData $pack, LevelData $level, array $learned): array;

    /**
     * The target's parts in answer order, as written; a part that comes
     * again is listed again.
     *
     * @return list<Tile>
     */
    abstract protected function answer(string $target, PackData $pack): array;

    /**
     * The decoys the target may get, as written, in groups from the most
     * wanted to the least; the order within a group is the random draw's.
     * Repeats and parts of the answer may be listed: they are dropped here.
     *
     * @param  list<string>  $learned
     * @return list<list<Tile>>
     */
    abstract protected function decoys(string $target, PackData $pack, LevelData $level, array $learned): array;

    abstract protected function prompt(string $target, PackData $pack, LetterCase $case, PromptTemplateData $template): PromptData;

    public function shortage(PackData $pack, LevelData $level, array $learned, ?array $letters = null): ?string
    {
        if (! $level->allows($this->mechanic())) {
            return Playable::message('level_mechanic');
        }

        $found = count($this->targets($pack, $level, $learned));

        return $found < RoundParts::MIN_TARGETS
            ? Playable::message($this->tooFew(), ['count' => RoundParts::MIN_TARGETS, 'found' => $found])
            : null;
    }

    public function build(GameSetupData $setup, PackData $pack, array $learned, Randomizer $random): array
    {
        RoundParts::ensurePack($setup, $pack);
        $level = $this->content->level($setup->level);

        if ($this->shortage($pack, $level, $learned) !== null) {
            throw new LogicException('There is not enough content for this game; GameSetupData::rules() should have refused it.');
        }

        $template = $this->content->prompt($this->mechanic(), $level->unit);
        $case = $setup->letter_case;
        $rounds = [];

        foreach (RoundParts::deck($this->targets($pack, $level, $learned), $setup->rounds, $random) as $index => $target) {
            $decoys = [];

            foreach ($this->decoyGroups($target, $pack, $level, $learned) as $group) {
                /** @var list<Tile> $group */
                $group = $random->shuffleArray($group);
                array_push($decoys, ...$group);
            }

            [$items, $solution] = $this->tiles($this->answer($target, $pack), array_slice($decoys, 0, $level->decoys ?? 0), $case, $random);

            $rounds[] = new RoundData(
                id: (string) Str::ulid(),
                index: $index,
                total: $setup->rounds,
                mechanic: $this->mechanic(),
                unit: $level->unit,
                pack: $pack->key,
                level: $level->key,
                prompt: $this->prompt($target, $pack, $case, $template),
                items: $items,
                slots: count($solution),
                solution: new SolutionData(SolutionOrder::Sequence, $solution),
                effect: $pack->style->effect,
            );
        }

        return $rounds;
    }

    /**
     * The targets that can make a round: buildable and with all of the
     * level's decoys.
     *
     * @param  list<string>  $learned
     * @return list<string>
     */
    private function targets(PackData $pack, LevelData $level, array $learned): array
    {
        $need = $level->decoys ?? 0;

        return array_values(array_filter(
            $this->candidates($pack, $level, $learned),
            fn (string $target): bool => count(array_merge(...$this->decoyGroups($target, $pack, $level, $learned))) >= $need,
        ));
    }

    /**
     * The decoy groups without the answer's parts and without repeats, both
     * compared case-insensitively; a repeat keeps its first place.
     *
     * @param  list<string>  $learned
     * @return list<list<Tile>>
     */
    private function decoyGroups(string $target, PackData $pack, LevelData $level, array $learned): array
    {
        $seen = [];

        foreach ($this->answer($target, $pack) as $part) {
            $seen[Az::lower($part['text'])] = true;
        }

        $groups = [];

        foreach ($this->decoys($target, $pack, $level, $learned) as $group) {
            $kept = [];

            foreach ($group as $tile) {
                $lower = Az::lower($tile['text']);

                if (! isset($seen[$lower])) {
                    $seen[$lower] = true;
                    $kept[] = $tile;
                }
            }

            $groups[] = $kept;
        }

        return $groups;
    }

    /**
     * The round's items and solution: one tile per cased text, shuffled
     * until the answer's tiles do not stand side by side in the answer's
     * order, ids `a`, `b`, `c`… in display order.
     *
     * @param  list<Tile>  $answer
     * @param  list<Tile>  $decoys
     * @return array{list<RoundItemData>, list<string>}
     */
    private function tiles(array $answer, array $decoys, LetterCase $case, Randomizer $random): array
    {
        $tiles = [];
        $texts = [];

        foreach ([...$answer, ...$decoys] as $tile) {
            $text = $case->apply($tile['text']);

            if (! in_array($text, $texts, true)) {
                $tiles[] = ['key' => $tile['key'], 'text' => $text];
                $texts[] = $text;
            }
        }

        $parts = array_map(static fn (array $part): string => $case->apply($part['text']), $answer);
        $firstUse = array_values(array_unique($parts));

        do {
            /** @var list<Tile> $tiles */
            $tiles = $random->shuffleArray($tiles);
            $shown = array_column($tiles, 'text');
        } while (self::standsBuilt($shown, $firstUse));

        $items = array_map(
            static fn (array $tile, int $position): RoundItemData => new RoundItemData(RoundParts::itemId($position), $tile['text'], null, $tile['key']),
            $tiles,
            array_keys($tiles),
        );
        $solution = array_map(
            static fn (string $part): string => RoundParts::itemId((int) array_search($part, $shown, true)),
            $parts,
        );

        return [$items, $solution];
    }

    /**
     * Whether the answer's tiles (two or more, in order of first use) stand
     * side by side in that order. Their reverse order never does, so a
     * reshuffle always ends.
     *
     * @param  list<string>  $shown  the tile texts in display order
     * @param  list<string>  $firstUse
     */
    private static function standsBuilt(array $shown, array $firstUse): bool
    {
        if (count($firstUse) < 2) {
            return false;
        }

        $start = array_search($firstUse[0], $shown, true);

        return $start !== false && array_slice($shown, $start, count($firstUse)) === $firstUse;
    }
}
