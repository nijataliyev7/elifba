<?php

namespace App\Domain\Play\Support;

use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Content\Support\ContentRepository;
use App\Domain\Family\Models\Child;
use App\Domain\Language\Support\AzCollator;
use App\Domain\Play\Data\GamePlayers;
use App\Domain\Play\Models\GameSession;
use App\Models\User;

/**
 * What the owner's phone chooses from (docs/architecture.md §4): the
 * account's live and paused games (`/pult`) and, for a guest board
 * (`/pult/{code}` and `/guest-boards/{board}/game`), also a teacher's active
 * classes and a parent's children (ADR 011), each with its setup form — the
 * same form and the same check as the setup page (`GameSetupOptions`).
 * Class names, the account's own children's names and game data only:
 * never a student's name.
 *
 * @phpstan-import-type PlayersSetupArray from GameSetupOptions
 *
 * @phpstan-type LiveGameArray array{id: string, status: string, mechanic: string, level: array{title: string, unit: string|null}, schoolClass: array{id: string, name: string}|null, child: array{id: string, display_name: string}|null, started_at: string}
 * @phpstan-type GuestBoardSetupArray array{
 *     packs: list<array{key: string, title: string, cover: string}>,
 *     levels: list<array{key: string, title: string, unit: string, mechanics: list<string>, hint: string}>,
 *     letterCases: list<string>,
 *     rounds: array{min: int, max: int, default: int},
 *     wordMaxLength: int,
 *     classes: array<string, PlayersSetupArray>,
 *     children: array<string, PlayersSetupArray>,
 * }
 */
final class PultChoices
{
    /**
     * The account's live and paused games, newest first, each with the
     * class or the child it is for and its level (the title in the
     * interface language, and the unit, so `pop` reads «De və vur» at a
     * level that does not read; docs/frontend.md rule 13). A level the
     * content no longer has keeps its key as the title and no unit.
     *
     * @return list<LiveGameArray>
     */
    public static function liveGames(User $owner): array
    {
        $levels = app(ContentRepository::class)->levels();
        $locale = app()->getLocale();

        return array_values(GameSession::query()
            ->open()
            ->where('user_id', $owner->id)
            ->with(['schoolClass:id,name', 'child:id,display_name'])
            ->orderByDesc('started_at')
            ->get()
            ->map(static fn (GameSession $game): array => [
                'id' => $game->id,
                'status' => $game->status->value,
                'mechanic' => $game->mechanic->value,
                'level' => [
                    'title' => isset($levels[$game->level]) ? $levels[$game->level]->title->in($locale) : $game->level,
                    'unit' => isset($levels[$game->level]) ? $levels[$game->level]->unit->value : null,
                ],
                'schoolClass' => $game->schoolClass === null ? null : ['id' => $game->schoolClass->id, 'name' => $game->schoolClass->name],
                'child' => $game->child === null ? null : ['id' => $game->child->id, 'display_name' => $game->child->display_name],
                'started_at' => $game->started_at->utc()->toIso8601ZuluString(),
            ])
            ->all());
    }

    /**
     * What a guest board can show: a live game, or a new game for one of
     * the account's active classes (sorted by `AzCollator`) or children (in
     * the parent's order), set up with that class's or child's own options.
     *
     * @return array{games: list<LiveGameArray>, classes: list<array{id: string, name: string}>, children: list<array{id: string, display_name: string}>, setup: GuestBoardSetupArray}
     */
    public static function forGuestBoard(User $owner): array
    {
        $classes = AzCollator::sortBy(
            // The setup options read the owner's packs through `$class->teacher`.
            SchoolClass::query()->where('user_id', $owner->id)->whereNull('archived_at')->get()
                ->each(static fn (SchoolClass $class): SchoolClass => $class->setRelation('teacher', $owner)),
            static fn (SchoolClass $class): string => $class->name,
        );
        $children = Child::query()->ownedBy($owner)->ordered()->get()
            ->each(static fn (Child $child): Child => $child->setRelation('parent', $owner));
        $perClass = [];
        $perChild = [];

        foreach ($classes as $class) {
            $perClass[$class->id] = GameSetupOptions::for(GamePlayers::forClass($class));
        }

        foreach ($children as $child) {
            $perChild[$child->id] = GameSetupOptions::for(GamePlayers::forChild($child));
        }

        return [
            'games' => self::liveGames($owner),
            'classes' => array_map(
                static fn (SchoolClass $class): array => ['id' => $class->id, 'name' => $class->name],
                $classes,
            ),
            'children' => array_values($children->map(
                static fn (Child $child): array => ['id' => $child->id, 'display_name' => $child->display_name],
            )->all()),
            'setup' => [...GameSetupOptions::shared(), 'classes' => $perClass, 'children' => $perChild],
        ];
    }
}
