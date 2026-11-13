<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Content\Support\TeacherPacks;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Enums\LetterCase;
use App\Domain\Play\Enums\SetupMode;

/**
 * What the game catalog offers (docs/architecture.md §2, "Game setup"), built
 * in one place for every page that shows it: the setup page on the laptop
 * (`/classes/{class}/games/create`, `/children/{child}/games/create`) and
 * the phone's guest board pages (`/pult/{code}`, `/guest-boards/{board}/game`,
 * §4). The packs, levels and letter cases are the same for every game (a
 * new game has `elifba.rounds.default` rounds; the board changes the count
 * before «Başla», §4 "Round count"); the options (with the reason a game
 * cannot start — the check `GameSetupData::rules()` makes, also for a game
 * of the owner's own words only) and the learned letters belong to one
 * class or one child (`GamePlayers`).
 *
 * The catalog (docs/frontend.md rule 13) only orders and explains these:
 * a pack's `cover` and `palette`, a level's `hint`, a game's name and a
 * card's name and sample are built here — every text with a case suffix
 * («Qatarla cümlə qur») comes from the server — and each refusal carries
 * its `code` (the key under `games.setup.errors.*`) beside the message, so
 * the catalog tells a learned-letters shortage from the rest without
 * reading the text. What the class or the child chose last
 * (`SetupMemory`) comes as `remembered`.
 *
 * @phpstan-import-type RememberedArray from SetupMemory
 *
 * @phpstan-type SetupArray array{
 *     packs: list<array{key: string, title: string, cover: string, palette: list<string>}>,
 *     levels: list<array{key: string, title: string, unit: string, mechanics: list<string>, hint: string}>,
 *     games: list<array{unit: string, mechanic: string, mode: string, name: string, hint: string}>,
 *     letterCases: list<string>,
 * }
 * @phpstan-type PlayersSetupArray array{
 *     options: list<array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null, code: string|null, own: array{startable: bool, reason: string|null, code: string|null}|null}>,
 *     cards: list<array{pack: string, unit: string, mechanic: string, title: string, sample: list<string>}>,
 *     letters: list<array{lower: string, upper: string}>,
 *     allLetters: bool,
 *     remembered: RememberedArray,
 * }
 */
final class GameSetupOptions
{
    /**
     * The choices every game shares, titles in the interface language.
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
                'cover' => ContentAssets::url($pack->style->cover, $pack->version),
                'palette' => $pack->style->palette,
            ], $content->packs())),
            'levels' => array_values(array_map(static fn (LevelData $level): array => [
                'key' => $level->key,
                'title' => $level->title->in($locale),
                'unit' => $level->unit->value,
                'mechanics' => array_map(static fn (Mechanic $mechanic): string => $mechanic->value, $level->mechanics),
                'hint' => self::hint($level),
            ], $content->levels())),
            'games' => array_map(static fn (array $game): array => [
                'unit' => $game[0]->value,
                'mechanic' => $game[1]->value,
                'mode' => SetupMode::of($game[1])->value,
                'name' => self::text("games.names.{$game[0]->value}_{$game[1]->value}"),
                'hint' => self::text('games.setup.mechanic_hints.'.($game[1] === Mechanic::Pop && ! $game[0]->reads() ? 'pop_say' : $game[1]->value)),
            ], self::games()),
            'letterCases' => array_map(static fn (LetterCase $case): string => $case->value, LetterCase::cases()),
        ];
    }

    /**
     * What this class or child can start: every option with its reason, a
     * card per pack and game, the learned letters for a letter level's
     * choice, and what was chosen last.
     *
     * @return PlayersSetupArray
     */
    public static function for(GamePlayers $players): array
    {
        $learned = $players->learned();
        $options = Playable::options($players->owner, $learned);
        $remembered = SetupMemory::recall($players);

        return [
            'options' => array_map(static fn (array $option): array => [
                'mechanic' => $option['mechanic']->value,
                'pack' => $option['pack']->key,
                'level' => $option['level']->key,
                'drawable' => Playable::draws($option['mechanic'], $option['pack']),
                'startable' => $option['reason'] === null,
                'reason' => $option['reason'],
                'code' => Playable::code($option['reason']),
                'own' => $option['own'] === null ? null : [
                    'startable' => $option['own']['reason'] === null,
                    'reason' => $option['own']['reason'],
                    'code' => Playable::code($option['own']['reason']),
                ],
            ], $options),
            'cards' => self::cards($players, $options, $remembered),
            'letters' => array_map(
                static fn (string $letter): array => ['lower' => $letter, 'upper' => Az::upper($letter)],
                $learned,
            ),
            'allLetters' => count($learned) === count(Alphabet::letters()),
            'remembered' => $remembered,
        ];
    }

    /**
     * Every game some level offers, as (unit, mechanic), in catalog order:
     * `Unit` order, then the level's mechanics.
     *
     * @return list<array{Unit, Mechanic}>
     */
    private static function games(): array
    {
        $levels = app(ContentRepository::class)->levels();
        $games = [];

        foreach (Unit::cases() as $unit) {
            foreach ($levels as $level) {
                foreach ($level->unit === $unit ? $level->mechanics : [] as $mechanic) {
                    $games["{$unit->value}|{$mechanic->value}"] = [$unit, $mechanic];
                }
            }
        }

        return array_values($games);
    }

    /**
     * One card per pack and game the content offers (the pack lists the
     * unit and a level of the unit lists the mechanic), the packs in their
     * order within each game: its name, `games.titles.{unit}_{mechanic}`
     * with the pack's `with` («Qatarla cümlə qur»), and its scene's sample
     * at its default level — the remembered level of its unit when it can
     * start with this card, else the first that can — cased with the
     * remembered case of the unit, else the level's default; none when the
     * card cannot start.
     *
     * @param  list<array{mechanic: Mechanic, pack: PackData, level: LevelData, reason: string|null, own: array{reason: string|null}|null}>  $options
     * @param  RememberedArray  $remembered
     * @return list<array{pack: string, unit: string, mechanic: string, title: string, sample: list<string>}>
     */
    private static function cards(GamePlayers $players, array $options, array $remembered): array
    {
        $locale = app()->getLocale();
        $learned = $players->learned();
        $hidden = app(TeacherPacks::class)->hiddenKeys($players->owner);
        $startable = [];

        foreach ($options as $option) {
            $startable["{$option['level']->unit->value}|{$option['mechanic']->value}|{$option['pack']->key}"][] = [$option['level'], $option['reason'] === null];
        }

        $cards = [];

        foreach (self::games() as [$unit, $mechanic]) {
            foreach (app(ContentRepository::class)->packs() as $pack) {
                $levels = $startable["{$unit->value}|{$mechanic->value}|{$pack->key}"] ?? null;

                if ($levels === null) {
                    continue;
                }

                $level = self::defaultLevel($levels, $remembered['levels'][$unit->value] ?? null);
                $case = LetterCase::tryFrom($remembered['letterCases'][$unit->value] ?? '') ?? ($unit === Unit::Letter ? LetterCase::Upper : LetterCase::Lower);

                $cards[] = [
                    'pack' => $pack->key,
                    'unit' => $unit->value,
                    'mechanic' => $mechanic->value,
                    'title' => self::text("games.titles.{$unit->value}_{$mechanic->value}", ['with' => $pack->with->in($locale)]),
                    'sample' => $level === null ? [] : CardSamples::of(
                        $mechanic,
                        TeacherPacks::merge($pack, [], $hidden[$pack->key] ?? []),
                        $level,
                        $learned,
                        $case,
                    ),
                ];
            }
        }

        return $cards;
    }

    /**
     * A card's default level: the remembered one when it can start, else
     * the first that can; null when none can.
     *
     * @param  list<array{LevelData, bool}>  $levels  in content order, each with whether it can start
     */
    private static function defaultLevel(array $levels, ?string $remembered): ?LevelData
    {
        $startable = array_values(array_map(
            static fn (array $level): LevelData => $level[0],
            array_filter($levels, static fn (array $level): bool => $level[1]),
        ));

        foreach ($startable as $level) {
            if ($level->key === $remembered) {
                return $level;
            }
        }

        return $startable[0] ?? null;
    }

    /**
     * A translation in the interface language.
     *
     * @param  array<string, string>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }

    /**
     * One line on what a level's rounds hold, from its data, in the
     * interface language (`games.setup.level_hints.{unit}`): the items on
     * the board for letters, pictures and numbers (`items`), the letters per
     * syllable (from its shapes), the syllables per word, the words per
     * sentence.
     */
    private static function hint(LevelData $level): string
    {
        $range = static fn (?RangeData $range): array => ['min' => $range->min ?? 0, 'max' => $range->max ?? 0];
        $lengths = array_map(strlen(...), $level->shapes);

        $replace = match ($level->unit) {
            Unit::Letter, Unit::Picture, Unit::Number => ['count' => $level->items ?? 0],
            Unit::Syllable => ['min' => $lengths === [] ? 0 : min($lengths), 'max' => $lengths === [] ? 0 : max($lengths)],
            Unit::Word => $range($level->syllables),
            Unit::Sentence => $range($level->words),
        };

        return self::text("games.setup.level_hints.{$level->unit->value}", array_map(strval(...), $replace));
    }
}
