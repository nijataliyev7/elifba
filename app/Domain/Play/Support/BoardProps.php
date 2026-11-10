<?php

namespace App\Domain\Play\Support;

use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Data\PackData;
use App\Domain\Content\Data\WordData;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Language\Support\Az;
use App\Domain\Play\Models\GameRound;
use App\Domain\Play\Models\GameSession;

/**
 * What a board draws around the rounds (docs/architecture.md §4), built in
 * one place for every board: the teacher board's page props
 * (`GET /games/{game}/board`), the guest board's JSON
 * (`GET /guest-boards/{board}`, which cannot make an Inertia visit with its
 * bearer) and the remote's page. It carries the game (with the points of
 * the rounds already solved, for the result screen), the class, the pack's
 * look with its sound URLs, and the class's active students for "who is at
 * the board". The rounds come from `GET /games/{game}/state` after the board
 * has claimed the game.
 *
 * A pack whose items stand in front of pictures (`animal`, `fruit`; §2
 * "Figures") also sends them: `pictures`, the pictures that show a content
 * key — a built-in word's own (`word:dəvəquşu` → the ostrich); for a letter
 * those of the pack's words that begin with it (`letter:a` → the horse, the
 * bear, the bee); for a syllable those of the words it begins
 * (`syllable:al` → the apple, the sour cherries, the plums), or when none
 * does, of the words it is a later syllable of (`syllable:ruz` → the
 * rooster). A letter or syllable that is not listed stands alone. `style.figures` are the pictures for the words without one
 * of their own (a sentence's other words, a teacher's word), each with the
 * content keys of the words it shows, so none of them stands in front of a
 * picture of its round's words. Both are empty for the other styles.
 *
 * @phpstan-type FigureArray array{url: string, words: list<string>}
 * @phpstan-type StudentArray array{id: string, display_name: string, avatar: array{key: string, url: string|null}}
 * @phpstan-type BoardPropsArray array{
 *     game: array{id: string, status: string, board_epoch: int, mechanic: string, level: string, letter_case: string, rounds: int, points: object},
 *     schoolClass: array{id: string, name: string},
 *     pack: array{key: string, title: string, style: array{item: string, slot: string, effect: string, palette: list<string>, backdrop: string|null, sounds: array<string, string|null>, figures: list<FigureArray>}, pictures: object},
 *     students: list<StudentArray>,
 * }
 */
final class BoardProps
{
    /**
     * @return BoardPropsArray
     */
    public static function of(GameSession $game): array
    {
        $pack = app(ContentRepository::class)->pack($game->pack);
        $style = $pack->style;
        $class = $game->schoolClass;
        $avatars = Avatars::images();
        $sounds = [];
        $rounds = GameRound::query()->where('game_session_id', $game->id)->get(['id', 'solved_at', 'points']);

        foreach (Sound::cases() as $sound) {
            $path = $style->sound($sound);
            $sounds[$sound->value] = $path === null ? null : ContentAssets::url($path, $pack->version);
        }

        return [
            'game' => [
                'id' => $game->id,
                'status' => $game->status->value,
                'board_epoch' => $game->board_epoch,
                'mechanic' => $game->mechanic->value,
                'level' => $game->level,
                'letter_case' => $game->letter_case->value,
                'rounds' => $rounds->count(),
                // The stars of the rounds solved so far, as stored; the board
                // adds what `/sync` returns after it loads.
                'points' => (object) $rounds
                    ->filter(static fn (GameRound $round): bool => $round->solved_at !== null)
                    ->mapWithKeys(static fn (GameRound $round): array => [$round->id => $round->points])
                    ->all(),
            ],
            'schoolClass' => ['id' => $class->id, 'name' => $class->name],
            'pack' => [
                'key' => $pack->key,
                'title' => $pack->title->in(app()->getLocale()),
                'style' => [
                    'item' => $style->item->value,
                    'slot' => $style->slot->value,
                    'effect' => $style->effect->value,
                    'palette' => $style->palette,
                    'backdrop' => $style->backdrop === null ? null : ContentAssets::url($style->backdrop, $pack->version),
                    'sounds' => $sounds,
                    'figures' => $style->item->hasFigures() ? self::figures($pack) : [],
                ],
                'pictures' => (object) ($style->item->hasFigures() ? self::pictures($pack) : []),
            ],
            'students' => array_values($class->students()->active()->ordered()->get()
                ->map(static fn (Student $student): array => [
                    'id' => $student->id,
                    'display_name' => $student->display_name,
                    'avatar' => [
                        'key' => $student->avatar,
                        'url' => isset($avatars[$student->avatar]) ? ContentAssets::url($avatars[$student->avatar]) : null,
                    ],
                ])
                ->all()),
        ];
    }

    /**
     * The pictures that show a content key, versioned, in pack order: a
     * built-in word's own; for a letter those of the words it begins; for a
     * syllable those of the words whose first syllable it is (`al` is
     * `alma`'s, `a` is `ananas`'s, not `alma`'s), or when there are none, of
     * the words it is a later syllable of (`ruz` of `xoruz`). A teacher's own
     * words have none.
     *
     * @return array<string, list<string>>
     */
    private static function pictures(PackData $pack): array
    {
        $pictures = [];
        $later = [];

        foreach ($pack->words as $word) {
            if ($word->image === null) {
                continue;
            }

            $url = ContentAssets::url($word->image, $pack->version);

            $pictures[$word->key()] = [$url];
            $pictures['letter:'.mb_substr(Az::lower($word->text), 0, 1)][] = $url;

            foreach ($word->syllables as $index => $syllable) {
                if ($index === 0) {
                    $pictures['syllable:'.Az::lower($syllable)][] = $url;
                } else {
                    $later['syllable:'.Az::lower($syllable)][] = $url;
                }
            }
        }

        foreach ($later as $key => $urls) {
            $pictures[$key] ??= array_values(array_unique($urls));
        }

        return $pictures;
    }

    /**
     * The pack's figures as the board loads them: a versioned URL, and the
     * content keys of the built-in words whose picture it is (a teacher's own
     * words have none).
     *
     * @return list<FigureArray>
     */
    private static function figures(PackData $pack): array
    {
        return array_map(
            static fn (string $path): array => [
                'url' => ContentAssets::url($path, $pack->version),
                'words' => array_values(array_map(
                    static fn (WordData $word): string => $word->key(),
                    array_filter($pack->words, static fn (WordData $word): bool => $word->image === $path),
                )),
            ],
            $pack->style->figures,
        );
    }
}
