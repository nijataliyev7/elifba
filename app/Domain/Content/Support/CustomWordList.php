<?php

namespace App\Domain\Content\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Models\CustomWord;
use App\Models\User;
use Illuminate\Support\Facades\Config;

/**
 * A teacher's own words as their pages show them (docs/architecture.md §3
 * "Teacher words", ADR 009): the words page (`customWords`, `levelTotals`,
 * `limits`) and «Sözlərim» on the game catalog (`myWords`, §2 "Game
 * setup"). Each word in the order it was added, with what it gives every
 * word and syllable level (`LevelData::itemsFrom()`) and the packs that have
 * it built in; how many distinct items all of them give each level; and the
 * limits of the add form. Titles are in the interface language; syllables
 * reach React already joined (`al-ma`).
 *
 * @phpstan-type CustomWordArray array{id: string, text: string, syllables: string, override: string|null, levels: list<array{title: string, unit: string, items: list<string>}>, built_in_in: list<string>}
 * @phpstan-type WordLimitsArray array{max_length: int, max_per_teacher: int, count: int}
 */
final readonly class CustomWordList
{
    public function __construct(private ContentRepository $content) {}

    /**
     * @return array{words: list<CustomWordArray>, levelTotals: list<array{title: string, count: int}>, limits: WordLimitsArray}
     */
    public function for(User $teacher): array
    {
        $packs = $this->content->packs();
        $locale = app()->getLocale();
        $levels = array_values(array_filter(
            $this->content->levels(),
            static fn (LevelData $level): bool => in_array($level->unit, [Unit::Word, Unit::Syllable], true),
        ));

        $words = CustomWord::query()
            ->where('user_id', $teacher->id)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
        /** @var array<string, array<string, true>> $totals level key → distinct items */
        $totals = array_fill_keys(array_map(static fn (LevelData $level): string => $level->key, $levels), []);

        $list = $words->map(static function (CustomWord $word) use ($levels, $packs, $locale, &$totals): array {
            $data = $word->toWordData();
            $gives = [];

            foreach ($levels as $level) {
                $items = $level->itemsFrom($data);

                foreach ($items as $item) {
                    $totals[$level->key][$item] = true;
                }

                if ($items !== []) {
                    $gives[] = ['title' => $level->title->in($locale), 'unit' => $level->unit->value, 'items' => $items];
                }
            }

            return [
                'id' => $word->id,
                'text' => $word->text,
                'syllables' => $data->hyphenated(),
                'override' => $word->syllables === null ? null : implode('-', $word->syllables),
                'levels' => $gives,
                // A pack that has the word built in plays its own, with the picture.
                'built_in_in' => array_values(array_map(
                    static fn (PackData $pack): string => $pack->title->in($locale),
                    array_filter($packs, static fn (PackData $pack): bool => $pack->word($word->text) !== null),
                )),
            ];
        })->all();

        return [
            'words' => array_values($list),
            'levelTotals' => array_map(static fn (LevelData $level): array => [
                'title' => $level->title->in($locale),
                'count' => count($totals[$level->key]),
            ], $levels),
            'limits' => [
                'max_length' => Config::integer('elifba.custom_words.max_length'),
                'max_per_teacher' => Config::integer('elifba.custom_words.max_per_teacher'),
                'count' => $words->count(),
            ],
        ];
    }

    /**
     * «Sözlərim» on a teacher's catalog page (§2 "Game setup"): the words and
     * the limits, without the level totals.
     *
     * @return array{words: list<CustomWordArray>, limits: WordLimitsArray}
     */
    public function mine(User $teacher): array
    {
        $list = $this->for($teacher);

        return ['words' => $list['words'], 'limits' => $list['limits']];
    }
}
