<?php

namespace App\Domain\Play\Support;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GameSetupData;
use App\Domain\Play\Enums\LetterCase;
use Illuminate\Support\Facades\Config;

/**
 * What the setup form offers (docs/architecture.md §2, "Game setup"), built
 * in one place for every page that shows it: the setup page on the laptop
 * (`/classes/{class}/games/create`) and the phone's guest board pages
 * (`/pult/{code}`, `/guest-boards/{board}/game`, §4). The packs, levels,
 * letter cases and round limits are the same for every class; the options
 * (with the reason a game cannot start — the check `GameSetupData::rules()`
 * makes) and the learned letters belong to one class.
 *
 * @phpstan-type SetupArray array{
 *     packs: list<array{key: string, title: string, style: array{item: string, effect: string, palette: list<string>}}>,
 *     levels: list<array{key: string, title: string, unit: string, mechanics: list<string>}>,
 *     letterCases: list<string>,
 *     rounds: array{min: int, max: int, default: int},
 * }
 * @phpstan-type ClassSetupArray array{
 *     options: list<array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null}>,
 *     letters: list<array{lower: string, upper: string}>,
 * }
 */
final class GameSetupOptions
{
    /**
     * The choices every class shares, titles in the interface language.
     *
     * @return SetupArray
     */
    public static function shared(): array
    {
        $content = app(ContentRepository::class);
        $locale = app()->getLocale();

        return [
            'packs' => array_values(array_map(static fn (PackData $pack): array => [
                'key' => $pack->key,
                'title' => $pack->title->in($locale),
                'style' => [
                    'item' => $pack->style->item->value,
                    'effect' => $pack->style->effect->value,
                    'palette' => $pack->style->palette,
                ],
            ], $content->packs())),
            'levels' => array_values(array_map(static fn (LevelData $level): array => [
                'key' => $level->key,
                'title' => $level->title->in($locale),
                'unit' => $level->unit->value,
                'mechanics' => array_map(static fn (Mechanic $mechanic): string => $mechanic->value, $level->mechanics),
            ], $content->levels())),
            'letterCases' => array_map(static fn (LetterCase $case): string => $case->value, LetterCase::cases()),
            'rounds' => [
                'min' => Config::integer('elifba.rounds.min'),
                'max' => Config::integer('elifba.rounds.max'),
                'default' => Config::integer('elifba.rounds.default'),
            ],
        ];
    }

    /**
     * What this class can start: every option with its reason, and the
     * class's learned letters for a letter level's choice.
     *
     * @return ClassSetupArray
     */
    public static function forClass(SchoolClass $class): array
    {
        $learned = GameSetupData::learned($class);

        return [
            'options' => array_map(static fn (array $option): array => [
                'mechanic' => $option['mechanic']->value,
                'pack' => $option['pack']->key,
                'level' => $option['level']->key,
                'drawable' => Playable::draws($option['mechanic'], $option['pack']),
                'startable' => $option['reason'] === null,
                'reason' => $option['reason'],
            ], Playable::options($class->teacher, $learned)),
            'letters' => array_map(
                static fn (string $letter): array => ['lower' => $letter, 'upper' => Az::upper($letter)],
                $learned,
            ),
        ];
    }
}
