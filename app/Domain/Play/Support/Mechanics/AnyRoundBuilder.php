<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\PromptTemplateData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\PromptData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Data\SolutionData;
use App\Domain\Play\Enums\SolutionOrder;
use App\Domain\Play\Support\Playable;
use App\Domain\Play\Support\PromptVoice;
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
 *   so a teacher's own words and their syllables play too. Pictures are the
 *   pack's words with a picture and numbers the level's range (R5, ADR
 *   012): neither reads the learned letters, and a teacher word, which has
 *   no picture, never becomes a picture item.
 * - Targets go round in shuffled decks (`RoundParts::deck()`), so every
 *   target comes up equally often and never twice in a row.
 * - Decoys for a letter: when the teacher chose letters, the chosen ones
 *   first — the target's confusables among them (`Alphabet::confusables`,
 *   most confusable first), then the other chosen letters — so a round shows
 *   only chosen letters whenever enough were chosen; then (and always,
 *   without a choice) its confusables among the learned letters, then other
 *   learned letters. For a number, the nearest numbers of the range
 *   (`RoundParts::nearest()`). For syllables, words and pictures, other
 *   items of the same pack and level.
 * - Items of a reading unit carry no picture: a picture would let a child
 *   match pictures instead of reading. A picture item is the picture
 *   (`image`), with its word as `text` for the phone; a number item is its
 *   digits.
 * - Text of a reading unit is cased here (`LetterCase`), never on the
 *   board; a picture's word and a number are written as they are.
 * - `prompt.audio` is the clip that says the prompt (`PromptVoice`, §3
 *   "Voice"): the target's for `find`, the template's for `pop`.
 *
 * The same setup, pack, letters and seed pick the same items for every
 * mechanic built on this class.
 */
abstract readonly class AnyRoundBuilder implements RoundBuilder
{
    public function __construct(
        protected ContentRepository $content,
        protected PromptVoice $voice,
    ) {}

    abstract protected function mechanic(): Mechanic;

    /**
     * The item ids that solve the round, in any order.
     *
     * @param  list<RoundItemData>  $items  in display order
     * @param  RoundItemData  $target  the item the deck dealt this round
     * @return list<string>
     */
    abstract protected function solution(array $items, RoundItemData $target): array;

    /**
     * @param  string|null  $audio  the clip that says it (`PromptVoice`), null for none
     */
    abstract protected function prompt(PromptTemplateData $template, RoundItemData $target, ?string $audio): PromptData;

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
            Unit::Picture => count($pool) < max($need, RoundParts::MIN_TARGETS)
                ? Playable::message('too_few_pictures', ['count' => max($need, RoundParts::MIN_TARGETS), 'found' => count($pool)])
                : null,
            Unit::Number => count($pool) < max($need, RoundParts::MIN_TARGETS)
                ? Playable::message('too_few_numbers', ['count' => max($need, RoundParts::MIN_TARGETS), 'found' => count($pool)])
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
        $targets = $this->targets($pool, $level, $setup->letters);
        $chosen = $level->unit === Unit::Letter && $setup->letters !== null ? $targets : null;
        $rounds = [];

        foreach (RoundParts::deck($targets, $setup->rounds, $random) as $index => $target) {
            $keys = $random->shuffleArray([$target, ...$this->decoys($target, $pool, $chosen, $decoys, $level->unit, $random)]);
            /** @var array<string, RoundItemData> $items content key → item, in display order */
            $items = [];

            foreach ($keys as $position => $key) {
                $items[$key] = $this->item(RoundParts::itemId($position), $key, $level->unit, $setup, $pack);
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
                prompt: $this->prompt($template, $items[$target], $this->voice->url($template, $target, $pack)),
                items: $shown,
                slots: 0,
                solution: new SolutionData(SolutionOrder::Any, $this->solution($shown, $items[$target])),
                effect: $pack->style->effect,
            );
        }

        return $rounds;
    }

    /**
     * One item: a reading unit's text cased, a picture's word as the pack
     * writes it with its picture, a number's digits.
     */
    private function item(string $id, string $key, Unit $unit, GameSetupData $setup, PackData $pack): RoundItemData
    {
        $written = RoundParts::written($key, $pack);

        if ($unit === Unit::Picture) {
            $image = $pack->word($written)->image ?? throw new LogicException('A picture item has no picture.');

            return new RoundItemData($id, $written, ContentAssets::url($image, $pack->version), $key);
        }

        return new RoundItemData($id, $unit->reads() ? $setup->letter_case->apply($written) : $written, null, $key);
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
     * The items beside the target, best first: the teacher's chosen letters
     * (the target's confusables among them, then the rest at random), then
     * the target's confusables among the pool, then the rest of the pool at
     * random. Without chosen letters only the last two steps run.
     *
     * @param  list<string>  $pool
     * @param  list<string>|null  $chosen  the teacher's letters among the pool, as content keys
     * @return list<string>
     */
    private function decoys(string $target, array $pool, ?array $chosen, int $count, Unit $unit, Randomizer $random): array
    {
        if ($unit === Unit::Number) {
            return RoundParts::nearest($target, $pool, $count, $random);
        }

        $confusables = $unit === Unit::Letter
            ? array_map(static fn (string $letter): string => "letter:{$letter}", Alphabet::confusables(RoundParts::value($target)))
            : [];
        $first = [];

        if ($chosen !== null) {
            $others = array_values(array_diff($chosen, [$target]));
            $near = array_values(array_intersect($confusables, $others));
            $first = [...$near, ...$random->shuffleArray(array_values(array_diff($others, $near)))];
        }

        $near = array_values(array_diff(array_intersect($confusables, $pool), $first));
        $rest = $random->shuffleArray(array_values(array_diff($pool, [$target], $first, $near)));

        return array_slice([...$first, ...$near, ...$rest], 0, $count);
    }
}
