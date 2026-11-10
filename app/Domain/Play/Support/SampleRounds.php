<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Data\RoundData;
use App\Domain\Play\Data\RoundItemData;
use App\Domain\Play\Enums\LetterCase;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Config;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The rounds the welcome page's wall plays (docs/frontend.md rule 10,
 * architecture §2 "The find builder"): `find` at the first letter level,
 * built by the real builder with every letter learned, so a visitor taps
 * the rounds a class would see. The pack and the letters come from a seed
 * of the Baku date: the same all day, different the next. Nothing is
 * stored.
 *
 * There is no teacher, so the pack is a built-in one from
 * `ContentRepository`, not `TeacherPacks`; a letter level takes only its
 * look.
 *
 * @phpstan-type SampleArray array{
 *     pack: array{key: string, title: string, item: string, palette: list<string>},
 *     rounds: list<array{prompt: string, show: string|null, items: list<array{id: string, text: string}>, solution: list<string>}>,
 * }
 */
final readonly class SampleRounds
{
    public function __construct(private ContentRepository $content) {}

    /**
     * The sample of one school day, titles in `$locale`; null when the
     * content has no letter level that `find` plays.
     *
     * @return SampleArray|null
     */
    public function on(CarbonInterface $date, string $locale): ?array
    {
        $level = $this->level();
        $packs = array_values(array_filter(
            $this->content->packs(),
            static fn (PackData $pack): bool => Playable::draws(Mechanic::Find, $pack),
        ));

        if ($level === null || $packs === []) {
            return null;
        }

        $random = new Randomizer(new Mt19937((int) SchoolCalendar::dateOf($date)->format('Ymd')));
        $pack = $packs[$random->getInt(0, count($packs) - 1)];
        $setup = new GameSetupData(
            mechanic: Mechanic::Find,
            pack: $pack->key,
            level: $level->key,
            rounds: Config::integer('elifba.rounds.min'),
            letter_case: LetterCase::Upper,
        );
        $rounds = Playable::builder(Mechanic::Find)->build($setup, $pack, Alphabet::letters(), $random);

        return [
            'pack' => [
                'key' => $pack->key,
                'title' => $pack->title->in($locale),
                'item' => $pack->style->item->value,
                'palette' => $pack->style->palette,
            ],
            'rounds' => array_map(static fn (RoundData $round): array => [
                'prompt' => $round->prompt->text,
                'show' => $round->prompt->show,
                'items' => array_map(
                    static fn (RoundItemData $item): array => ['id' => $item->id, 'text' => $item->text],
                    $round->items,
                ),
                'solution' => $round->solution->item_ids,
            ], $rounds),
        ];
    }

    /**
     * The first level, in the file's order, that `find` plays with letters.
     */
    private function level(): ?LevelData
    {
        foreach ($this->content->levels() as $level) {
            if ($level->unit === Unit::Letter && in_array(Mechanic::Find, $level->mechanics, true)) {
                return $level;
            }
        }

        return null;
    }
}
