<?php

namespace App\Domain\Play\Support;

use App\Domain\Classroom\Models\Student;
use App\Domain\Content\Enums\Sound;
use App\Domain\Content\Support\Avatars;
use App\Domain\Content\Support\ContentAssets;
use App\Domain\Content\Support\ContentRepository;
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
 * the board". The rounds come from `GET /games/{game}/state` after the
 * board has claimed the game.
 *
 * @phpstan-type StudentArray array{id: string, display_name: string, avatar: array{key: string, url: string|null}}
 * @phpstan-type BoardPropsArray array{
 *     game: array{id: string, status: string, board_epoch: int, mechanic: string, level: string, letter_case: string, rounds: int, points: object},
 *     schoolClass: array{id: string, name: string},
 *     pack: array{key: string, title: string, style: array{item: string, slot: string, effect: string, palette: list<string>, backdrop: string|null, sounds: array<string, string|null>}},
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
                ],
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
}
