<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A game is for a class or for one child (docs/architecture.md §1, ADR 011):
 * `school_class_id` becomes nullable on games and rounds, and `child_id`
 * (cascade) is added to both; `StartGame` sets exactly one of them and the
 * rounds copy it, for the rankings and for a child's stars (§5). Existing
 * games keep their class. On SQLite a changed column rebuilds the table with
 * the foreign keys switched off (migrations run outside a transaction
 * there), so no row is lost; PostgreSQL alters the columns in place.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->ulid('school_class_id')->nullable()->change();
            $table->foreignUlid('child_id')->nullable()->constrained('children')->cascadeOnDelete();
        });

        Schema::table('game_rounds', function (Blueprint $table) {
            $table->ulid('school_class_id')->nullable()->change();
            $table->foreignUlid('child_id')->nullable()->constrained('children')->cascadeOnDelete();

            $table->index(['child_id', 'played_on']);
            $table->index(['child_id', 'week_start']);
        });
    }

    /**
     * Home games have no class to go back to: they are deleted, with their
     * rounds and answers.
     */
    public function down(): void
    {
        $home = DB::table('game_sessions')->whereNull('school_class_id')->pluck('id')->all();

        foreach (array_chunk($home, 500) as $ids) {
            $rounds = DB::table('game_rounds')->whereIn('game_session_id', $ids)->pluck('id')->all();

            foreach (array_chunk($rounds, 500) as $roundIds) {
                DB::table('game_answers')->whereIn('game_round_id', $roundIds)->delete();
            }

            DB::table('game_rounds')->whereIn('game_session_id', $ids)->delete();
            DB::table('guest_boards')->whereIn('game_session_id', $ids)->update(['game_session_id' => null]);
            DB::table('game_sessions')->whereIn('id', $ids)->delete();
        }

        Schema::table('game_rounds', function (Blueprint $table) {
            $table->dropIndex(['child_id', 'played_on']);
            $table->dropIndex(['child_id', 'week_start']);
            $table->dropConstrainedForeignId('child_id');
            $table->ulid('school_class_id')->nullable(false)->change();
        });

        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('child_id');
            $table->ulid('school_class_id')->nullable(false)->change();
        });
    }
};
