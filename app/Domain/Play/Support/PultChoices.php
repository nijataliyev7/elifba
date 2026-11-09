<?php

namespace App\Domain\Play\Support;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Language\Support\AzCollator;
use App\Domain\Play\Models\GameSession;
use App\Models\User;

/**
 * What the teacher's phone chooses from (docs/architecture.md §4): the
 * teacher's live and paused games (`/pult`) and, for a guest board
 * (`/pult/{code}` and `/guest-boards/{board}/game`), also their active
 * classes with the setup form for each — the same form and the same check
 * as the setup page (`GameSetupOptions`). Class names and game data only:
 * never a student's name.
 *
 * @phpstan-import-type ClassSetupArray from GameSetupOptions
 *
 * @phpstan-type LiveGameArray array{id: string, status: string, mechanic: string, level: string, schoolClass: array{id: string, name: string}, started_at: string}
 * @phpstan-type GuestBoardSetupArray array{
 *     packs: list<array{key: string, title: string, style: array{item: string, effect: string, palette: list<string>}}>,
 *     levels: list<array{key: string, title: string, unit: string, mechanics: list<string>}>,
 *     letterCases: list<string>,
 *     rounds: array{min: int, max: int, default: int},
 *     classes: array<string, ClassSetupArray>,
 * }
 */
final class PultChoices
{
    /**
     * The teacher's live and paused games, newest first.
     *
     * @return list<LiveGameArray>
     */
    public static function liveGames(User $teacher): array
    {
        return array_values(GameSession::query()
            ->open()
            ->where('user_id', $teacher->id)
            ->with('schoolClass:id,name')
            ->orderByDesc('started_at')
            ->get()
            ->map(static fn (GameSession $game): array => [
                'id' => $game->id,
                'status' => $game->status->value,
                'mechanic' => $game->mechanic->value,
                'level' => $game->level,
                'schoolClass' => ['id' => $game->schoolClass->id, 'name' => $game->schoolClass->name],
                'started_at' => $game->started_at->utc()->toIso8601ZuluString(),
            ])
            ->all());
    }

    /**
     * What a guest board can show: a live game, or a new game for one of
     * the teacher's active classes (sorted by `AzCollator`), set up with
     * the class's own options.
     *
     * @return array{games: list<LiveGameArray>, classes: list<array{id: string, name: string}>, setup: GuestBoardSetupArray}
     */
    public static function forGuestBoard(User $teacher): array
    {
        $classes = AzCollator::sortBy(
            // The setup options read the teacher's packs through `$class->teacher`.
            SchoolClass::query()->where('user_id', $teacher->id)->whereNull('archived_at')->get()
                ->each(static fn (SchoolClass $class): SchoolClass => $class->setRelation('teacher', $teacher)),
            static fn (SchoolClass $class): string => $class->name,
        );
        $perClass = [];

        foreach ($classes as $class) {
            $perClass[$class->id] = GameSetupOptions::forClass($class);
        }

        return [
            'games' => self::liveGames($teacher),
            'classes' => array_map(
                static fn (SchoolClass $class): array => ['id' => $class->id, 'name' => $class->name],
                $classes,
            ),
            'setup' => [...GameSetupOptions::shared(), 'classes' => $perClass],
        ];
    }
}
