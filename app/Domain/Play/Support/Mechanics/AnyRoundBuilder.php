<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Data\SolutionData;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Playable;
use Illuminate\Support\Str;
use LogicException;
use Random\Randomizer;

/**
 * The flow of the mechanics that do not build (`find`, `pop`;
 * docs/architecture.md §2, "The find builder" and "The pop builder"): the
 * level's `items` float on the board and are hit in any order. This class
 * picks each round's items; a mechanic says only what solves the round and
 * what the prompt says.
 *
 * - Content: letters are the class's learned letters (targets: the
 *   teacher's choice among them, when given); syllables and words are
 *   `LevelData::keysFrom()` of the teacher's pack with the learned letters,
 *   so a teacher's own words and their syllables play too.
 * - Targets go round in shuffled decks (`RoundParts::deck()`), so every
 *   target comes up equally often and never twice in a row.
 * - Decoys for a letter are its confusables first (`Alphabet::confusables`,
 *   most confusable first), then other learned letters; for syllables and
 *   words, other items of the same pack and level.
 * - Items carry no picture: a picture would let a child match pictures
 *   instead of reading.
 * - Text is cased here (`LetterCase`), never on the board.
 *
 * The same setup, pack, letters and seed pick the same items for every
 * mechanic built on this class.
 */
abstract readonly class AnyRoundBuilder implements RoundBuilder
{
    public function __construct(protected ContentRepository $content) {}

    abstract protected function mechanic(): Mechanic;

    /**
     * The item ids that solve the round, in any order.
     *
     * @param  list<RoundItemData>  $items  in display order
     * @param  RoundItemData  $target  the item the deck dealt this round
     * @return list<string>
     */
    abstract protected function solution(array $items, RoundItemData $target): array;

    abstract protected function prompt(PromptTemplateData $template, RoundItemData $target): PromptData;

    public function shortage(PackData $pack, LevelData $level, array $learned, ?array $letters = null): ?string
    {
        $need = $level->items ?? 0;
        $pool = $this->pool($pack, $level, $learned);

        return match ($level->unit) {
            Unit::Letter => match (true) {
                count($pool) < $need => Playable::message('too_few_letters', ['count' => $need]),
                count($this->targets($pool, $level, $letters)) < RoundParts::MIN_TARGETS => Playable::message('too_few_targets', ['count' => RoundParts::MIN_TARGETS]),
                default => null,
            },
            Unit::Syllable => count($pool) < max($need, RoundParts::MIN_TARGETS)
                ? Playable::message('too_few_syllables', ['count' => $need, 'found' => count($pool)])
                : null,
            Unit::Word => count($pool) < max($need, RoundParts::MIN_TARGETS)
                ? Playable::message('too_few_words', ['count' => $need, 'found' => count($pool)])
                : null,
            Unit::Sentence => Playable::message('level_mechanic'),
        };
    }

    public function build(GameSetupData $setup, PackData $pack, array $learned, Randomizer $random): array
    {
        RoundParts::ensurePack($setup, $pack);
        $level = $this->content->level($setup->level);

        if ($this->shortage($pack, $level, $learned, $setup->letters) !== null) {
            throw new LogicException('There is not enough content for this game; GameSetupData::rules() should have refused it.');
        }

        $pool = $this->pool($pack, $level, $learned);
        $template = $this->content->prompt($this->mechanic(), $level->unit);
        $decoys = ($level->items ?? 0) - 1;
        $rounds = [];

        foreach (RoundParts::deck($this->targets($pool, $level, $setup->letters), $setup->rounds, $random) as $index => $target) {
            $keys = $random->shuffleArray([$target, ...$this->decoys($target, $pool, $decoys, $level->unit, $random)]);
            /** @var array<string, RoundItemData> $items content key → item, in display order */
            $items = [];

            foreach ($keys as $position => $key) {
                $items[$key] = new RoundItemData(RoundParts::itemId($position), $setup->letter_case->apply(RoundParts::written($key, $pack)), null, $key);
            }

            $shown = array_values($items);

            $rounds[] = new RoundData(
                id: (string) Str::ulid(),
                index: $index,
                total: $setup->rounds,
                mechanic: $this->mechanic(),
                unit: $level->unit,
                pack: $pack->key,
                level: $level->key,
                prompt: $this->prompt($template, $items[$target]),
                items: $shown,
                slots: 0,
                solution: new SolutionData(SolutionOrder::Any, $this->solution($shown, $items[$target])),
                effect: $pack->style->effect,
            );
        }

        return $rounds;
    }

    /**
     * Every item the level may show for the class, as content keys.
     *
     * @param  list<string>  $learned
     * @return list<string>
     */
    private function pool(PackData $pack, LevelData $level, array $learned): array
    {
        return $level->unit === Unit::Letter
            ? array_map(static fn (string $letter): string => "letter:{$letter}", Alphabet::sort($learned))
            : $level->keysFrom($pack, $learned);
    }

    /**
     * The items the deck deals: the teacher's letters among the learned
     * ones for a letter level, every item of the pool otherwise.
     *
     * @param  list<string>  $pool
     * @param  list<string>|null  $letters
     * @return list<string>
     */
    private function targets(array $pool, LevelData $level, ?array $letters): array
    {
        if ($level->unit !== Unit::Letter || $letters === null) {
            return $pool;
        }

        $chosen = array_map(static fn (string $letter): string => "letter:{$letter}", $letters);

        return array_values(array_intersect($pool, $chosen));
    }

    /**
     * @param  list<string>  $pool
     * @return list<string>
     */
    private function decoys(string $target, array $pool, int $count, Unit $unit, Randomizer $random): array
    {
        $near = [];

        if ($unit === Unit::Letter) {
            $confusables = array_map(
                static fn (string $letter): string => "letter:{$letter}",
                Alphabet::confusables(RoundParts::value($target)),
            );
            $near = array_values(array_intersect($confusables, $pool));
        }

        $rest = $random->shuffleArray(array_values(array_diff($pool, [$target], $near)));

        return array_slice([...$near, ...$rest], 0, $count);
    }
}
