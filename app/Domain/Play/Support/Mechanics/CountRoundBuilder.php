<?php

namespace App\Domain\Play\Support\Mechanics;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
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
 * Rounds of `count` («Say», «Neçə alma var?»; docs/architecture.md §2 "The
 * count builder", ADR 012): the prompt shows one picture of the pack N
 * times, the items are numbers, and the one that says N solves the round.
 *
 * - What is counted: a word of the pack with a picture, from a deck over
 *   those words (`RoundParts::deck()`), so each round counts another thing
 *   and none twice in a row. A teacher word has no picture and is never
 *   counted; a word the owner hid is gone.
 * - How many: N from a second deck over the level's range, so every number
 *   comes up equally often (±1) and never twice in a row.
 * - The prompt: `Neçə «{x}» var?` with the word as the pack writes it, its
 *   picture as `image` and N as `count`; `show` is null, so the number is
 *   never written on the board. `audio` says it (`PromptVoice`).
 * - The items: N and its nearest numbers in the range (`RoundParts::nearest()`),
 *   the level's `items` of them, shuffled, with ids in display order.
 */
final readonly class CountRoundBuilder implements RoundBuilder
{
    public function __construct(
        private ContentRepository $content,
        private PromptVoice $voice,
    ) {}

    public function shortage(PackData $pack, LevelData $level, array $learned, ?array $letters = null): ?string
    {
        if ($level->unit !== Unit::Number || ! $level->allows(Mechanic::Count)) {
            return Playable::message('level_mechanic');
        }

        $counted = count($pack->pictured());

        if ($counted < RoundParts::MIN_TARGETS) {
            return Playable::message('too_few_pictures', ['count' => RoundParts::MIN_TARGETS, 'found' => $counted]);
        }

        $numbers = count($level->keysFrom($pack));
        $need = max($level->items ?? 0, RoundParts::MIN_TARGETS);

        return $numbers < $need ? Playable::message('too_few_numbers', ['count' => $need, 'found' => $numbers]) : null;
    }

    public function build(GameSetupData $setup, PackData $pack, array $learned, Randomizer $random): array
    {
        RoundParts::ensurePack($setup, $pack);
        $level = $this->content->level($setup->level);

        if ($this->shortage($pack, $level, $learned) !== null) {
            throw new LogicException('There is not enough content for this game; GameSetupData::rules() should have refused it.');
        }

        $template = $this->content->prompt(Mechanic::Count, Unit::Number);
        $range = $level->keysFrom($pack);
        $things = RoundParts::deck(array_map(static fn (WordData $word): string => $word->key(), $pack->pictured()), $setup->rounds, $random);
        $numbers = RoundParts::deck($range, $setup->rounds, $random);
        $rounds = [];

        foreach ($numbers as $index => $answer) {
            $word = $pack->word(RoundParts::value($things[$index])) ?? throw new LogicException('A counted word is not in the pack.');
            $image = $word->image ?? throw new LogicException('A counted word has no picture.');
            /** @var list<string> $keys */
            $keys = $random->shuffleArray([$answer, ...RoundParts::nearest($answer, $range, ($level->items ?? 0) - 1, $random)]);
            $items = [];
            $solution = '';

            foreach ($keys as $position => $key) {
                $items[] = new RoundItemData(RoundParts::itemId($position), RoundParts::value($key), null, $key);

                if ($key === $answer) {
                    $solution = RoundParts::itemId($position);
                }
            }

            $rounds[] = new RoundData(
                id: (string) Str::ulid(),
                index: $index,
                total: $setup->rounds,
                mechanic: Mechanic::Count,
                unit: Unit::Number,
                pack: $pack->key,
                level: $level->key,
                prompt: new PromptData(
                    text: $template->fill($word->text),
                    show: null,
                    image: ContentAssets::url($image, $pack->version),
                    count: (int) RoundParts::value($answer),
                    audio: $this->voice->url($template, $things[$index], $pack),
                    tail: null,
                ),
                items: $items,
                slots: 0,
                solution: new SolutionData(SolutionOrder::Any, [$solution]),
                effect: $pack->style->effect,
            );
        }

        return $rounds;
    }
}
