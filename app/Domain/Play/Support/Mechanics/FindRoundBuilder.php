<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
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
 * Rounds of `find` («Tap!», "Şarı partlat" with the balloons pack;
 * docs/architecture.md §2): a prompt names a target, `items` of the level
 * float on the board, one of them is the target.
 *
 * - Content: letters are the class's learned letters (targets: the
 *   teacher's choice among them, when given); syllables and words are
 *   `LevelData::keysFrom()` of the pack with the learned letters.
 * - Targets go round in shuffled decks (`RoundParts::deck()`), so every
 *   target comes up equally often and never twice in a row.
 * - Decoys for a letter are its confusables first (`Alphabet::confusables`,
 *   most confusable first), then other learned letters; for syllables and
 *   words, other items of the same pack and level.
 * - Items and the prompt carry no picture: a picture would let a child
 *   match pictures instead of reading. The prompt shows the target as text.
 * - Text is cased here (`LetterCase`), never on the board.
 */
final readonly class FindRoundBuilder implements RoundBuilder
{
    public function __construct(private ContentRepository $content) {}

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

    public function build(GameSetupData $setup, array $learned, Randomizer $random): array
    {
        $pack = $this->content->pack($setup->pack);
        $level = $this->content->level($setup->level);

        if ($this->shortage($pack, $level, $learned, $setup->letters) !== null) {
            throw new LogicException('There is not enough content for this game; GameSetupData::rules() should have refused it.');
        }

        $pool = $this->pool($pack, $level, $learned);
        $template = $this->content->prompt(Mechanic::Find, $level->unit);
        $decoys = ($level->items ?? 0) - 1;
        $rounds = [];

        foreach (RoundParts::deck($this->targets($pool, $level, $setup->letters), $setup->rounds, $random) as $index => $target) {
            $keys = $random->shuffleArray([$target, ...$this->decoys($target, $pool, $decoys, $level->unit, $random)]);
            $items = [];
            $targetId = '';

            foreach ($keys as $position => $key) {
                $item = new RoundItemData(RoundParts::itemId($position), $setup->letter_case->apply(RoundParts::written($key, $pack)), null, $key);
                $items[] = $item;

                if ($key === $target) {
                    $targetId = $item->id;
                }
            }

            $show = $setup->letter_case->apply(RoundParts::written($target, $pack));

            $rounds[] = new RoundData(
                id: (string) Str::ulid(),
                index: $index,
                total: $setup->rounds,
                mechanic: Mechanic::Find,
                unit: $level->unit,
                pack: $pack->key,
                level: $level->key,
                prompt: new PromptData(text: $template->fill($show), show: $show, image: null, audio: null, tail: null),
                items: $items,
                slots: 0,
                solution: new SolutionData(SolutionOrder::Any, [$targetId]),
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
     * The items that may be asked for: the teacher's letters among the
     * learned ones for a letter level, every item of the pool otherwise.
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
