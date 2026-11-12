<?php

namespace App\Domain\Play\Support;

use App\Domain\Content\Data\LevelData;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\RangeData;
use App\Domain\Content\Enums\Mechanic;
use App\Domain\Content\Enums\Unit;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Alphabet;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Enums\LetterCase;
use Illuminate\Support\Facades\Config;

/**
 * What the setup form offers (docs/architecture.md §2, "Game setup"), built
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
 * The setup form (docs/frontend.md rule 13) only orders and explains these:
 * a pack's `cover` and a level's `hint` are built here, and each refusal
 * carries its `code` (the key under `games.setup.errors.*`) beside the
 * message, so the form tells a learned-letters shortage from the rest
 * without reading the text.
 *
 * @phpstan-type SetupArray array{
 *     packs: list<array{key: string, title: string, cover: string}>,
 *     levels: list<array{key: string, title: string, unit: string, mechanics: list<string>, hint: string}>,
 *     letterCases: list<string>,
 *     wordMaxLength: int,
 * }
 * @phpstan-type PlayersSetupArray array{
 *     options: list<array{mechanic: string, pack: string, level: string, drawable: bool, startable: bool, reason: string|null, code: string|null, own: array{startable: bool, reason: string|null, code: string|null}|null}>,
 *     letters: list<array{lower: string, upper: string}>,
 *     allLetters: bool,
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
            ], $content->packs())),
            'levels' => array_values(array_map(static fn (LevelData $level): array => [
                'key' => $level->key,
                'title' => $level->title->in($locale),
                'unit' => $level->unit->value,
                'mechanics' => array_map(static fn (Mechanic $mechanic): string => $mechanic->value, $level->mechanics),
                'hint' => self::hint($level),
            ], $content->levels())),
            'letterCases' => array_map(static fn (LetterCase $case): string => $case->value, LetterCase::cases()),
            // For adding a teacher word from the form (§3 "Teacher words").
            'wordMaxLength' => Config::integer('elifba.custom_words.max_length'),
        ];
    }

    /**
     * What this class or child can start: every option with its reason, and
     * the learned letters for a letter level's choice.
     *
     * @return PlayersSetupArray
     */
    public static function for(GamePlayers $players): array
    {
        $learned = $players->learned();

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
            ], Playable::options($players->owner, $learned)),
            'letters' => array_map(
                static fn (string $letter): array => ['lower' => $letter, 'upper' => Az::upper($letter)],
                $learned,
            ),
            'allLetters' => count($learned) === count(Alphabet::letters()),
        ];
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
        $hint = __("games.setup.level_hints.{$level->unit->value}", $replace);

        return is_string($hint) ? $hint : $level->unit->value;
    }
}
