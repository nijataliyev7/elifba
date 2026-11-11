<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * The migration that lets a game be for a class or for a child (ADR 011):
 * `school_class_id` becomes nullable on games and rounds, `child_id` is
 * added, and every existing game keeps its class, rounds and answers.
 *
 * On SQLite a changed column rebuilds the table, which is safe only with the
 * foreign keys off — and SQLite ignores that switch inside a transaction,
 * where each test runs. So on SQLite the migration runs as it does for real:
 * on a database of its own, outside any transaction. PostgreSQL alters the
 * columns in place and runs it inside the test's transaction.
 */

/**
 * Runs the test on a database the migrations can rebuild tables in.
 */
function onMigrationDatabase(Closure $test): void
{
    if (DB::connection()->getDriverName() !== 'sqlite') {
        $test();

        return;
    }

    $default = DB::getDefaultConnection();
    Config::set('database.connections.migration_test', [...Config::array("database.connections.{$default}"), 'database' => ':memory:']);
    DB::setDefaultConnection('migration_test');

    try {
        Artisan::call('migrate', ['--database' => 'migration_test', '--force' => true]);
        $test();
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('migration_test');
    }
}

it('keeps every existing game with its class', function (): void {
    $migration = require database_path('migrations/2026_11_18_000002_add_child_to_games.php');

    onMigrationDatabase(function () use ($migration): void {
        $migration->down();

        expect(Schema::hasColumn('game_sessions', 'child_id'))->toBeFalse()
            ->and(Schema::hasColumn('game_rounds', 'child_id'))->toBeFalse();

        // A class's game as it was stored before R3, with the query builder.
        $now = '2026-11-11 08:00:00';
        $teacher = DB::table('users')->insertGetId([
            'name' => 'Müəllim', 'email' => 'm@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $class = (string) Str::ulid();
        $student = (string) Str::ulid();
        $game = (string) Str::ulid();
        $board = (string) Str::ulid();
        DB::table('school_classes')->insert(['id' => $class, 'user_id' => $teacher, 'name' => '1A', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('students')->insert(['id' => $student, 'school_class_id' => $class, 'display_name' => 'Aysel', 'avatar' => 'bear', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('game_sessions')->insert([
            'id' => $game, 'user_id' => $teacher, 'school_class_id' => $class, 'mechanic' => 'find', 'pack' => 'balloons', 'pack_version' => 1,
            'level' => 'letters', 'letter_case' => 'upper', 'seed' => 7, 'status' => 'live', 'board_epoch' => 2,
            'started_at' => $now, 'last_activity_at' => $now,
        ]);
        $rounds = [];

        foreach ([0, 1] as $position) {
            $rounds[$position] = (string) Str::ulid();
            DB::table('game_rounds')->insert([
                'id' => $rounds[$position], 'game_session_id' => $game, 'school_class_id' => $class, 'position' => $position,
                'payload' => '{}', 'progress' => '[]', 'misses' => 0, 'points' => $position === 0 ? 3 : 0,
                'solved_at' => $position === 0 ? $now : null, 'student_id' => $position === 0 ? $student : null,
                'played_on' => $position === 0 ? '2026-11-11' : null, 'week_start' => $position === 0 ? '2026-11-09' : null,
            ]);
        }

        DB::table('game_answers')->insert([
            'event_id' => (string) Str::ulid(), 'game_round_id' => $rounds[0], 'student_id' => $student, 'item_id' => 'b',
            'content_key' => 'letter:b', 'correct' => true, 'source' => 'board', 'board_epoch' => 2, 'created_at' => $now,
        ]);
        DB::table('guest_boards')->insert([
            'id' => $board, 'secret_hash' => str_repeat('a', 64), 'user_id' => $teacher, 'game_session_id' => $game,
            'claimed_at' => $now, 'expires_at' => '2026-11-11 20:00:00', 'created_at' => $now, 'updated_at' => $now,
        ]);

        $migration->up();

        expect(Schema::hasColumn('game_sessions', 'child_id'))->toBeTrue()
            ->and(Schema::hasColumn('game_rounds', 'child_id'))->toBeTrue()
            ->and((array) DB::table('game_sessions')->where('id', $game)->first(['user_id', 'school_class_id', 'child_id', 'board_epoch']))
            ->toEqual(['user_id' => $teacher, 'school_class_id' => $class, 'child_id' => null, 'board_epoch' => 2])
            ->and(DB::table('game_rounds')->where('game_session_id', $game)->orderBy('position')->get(['id', 'school_class_id', 'child_id', 'student_id', 'points'])->map(static fn (object $row): array => (array) $row)->all())
            ->toEqual([
                ['id' => $rounds[0], 'school_class_id' => $class, 'child_id' => null, 'student_id' => $student, 'points' => 3],
                ['id' => $rounds[1], 'school_class_id' => $class, 'child_id' => null, 'student_id' => null, 'points' => 0],
            ])
            ->and(DB::table('game_answers')->where('game_round_id', $rounds[0])->count())->toBe(1)
            ->and(DB::table('guest_boards')->where('id', $board)->value('game_session_id'))->toBe($game);

        // The foreign keys survived the rebuild: deleting the class still takes its game.
        DB::table('school_classes')->where('id', $class)->delete();

        expect(DB::table('game_sessions')->count())->toBe(0)
            ->and(DB::table('game_rounds')->count())->toBe(0)
            ->and(DB::table('game_answers')->count())->toBe(0)
            ->and(DB::table('guest_boards')->where('id', $board)->value('game_session_id'))->toBeNull();

        // A home game now fits: no class, a child.
        $parent = DB::table('users')->insertGetId([
            'name' => 'Valideyn', 'email' => 'v@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $child = (string) Str::ulid();
        DB::table('children')->insert(['id' => $child, 'user_id' => $parent, 'display_name' => 'Tural', 'avatar' => 'bear', 'position' => 1, 'created_at' => $now, 'updated_at' => $now]);
        DB::table('game_sessions')->insert([
            'id' => (string) Str::ulid(), 'user_id' => $parent, 'school_class_id' => null, 'child_id' => $child, 'mechanic' => 'find', 'pack' => 'balloons',
            'pack_version' => 1, 'level' => 'letters', 'letter_case' => 'upper', 'seed' => 7, 'status' => 'live', 'board_epoch' => 0,
            'started_at' => $now, 'last_activity_at' => $now,
        ]);

        expect(DB::table('game_sessions')->whereNull('school_class_id')->count())->toBe(1);
    });
});
