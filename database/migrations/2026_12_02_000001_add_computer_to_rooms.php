<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The computer's seat (docs/architecture.md §1 "Tables", §12 "The
 * computer's seat", ADR 036 decisions 1, 2 and 8), additive and nullable:
 * the level of the computer in the room's choice of a board game
 * (`rooms.setup_computer`, written by `ChooseRoomGame`), the level a board
 * game was started with (`room_games.computer`; the seat it plays is the
 * one without a player), and an act without a player — the computer's
 * move (`room_acts.room_player_id`, written null only by
 * `PlayComputerMove`). On SQLite a changed column rebuilds the table with
 * its rows and keys kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->unsignedTinyInteger('setup_computer')->nullable();
        });

        Schema::table('room_games', function (Blueprint $table) {
            $table->unsignedTinyInteger('computer')->nullable();
        });

        Schema::table('room_acts', function (Blueprint $table) {
            $table->ulid('room_player_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('room_acts')->whereNull('room_player_id')->delete();

        Schema::table('room_acts', function (Blueprint $table) {
            $table->ulid('room_player_id')->nullable(false)->change();
        });

        Schema::table('room_games', function (Blueprint $table) {
            $table->dropColumn('computer');
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('setup_computer');
        });
    }
};
