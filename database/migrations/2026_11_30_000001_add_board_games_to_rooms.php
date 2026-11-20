<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Board games in a room (docs/architecture.md §1 "Tables", §12 "Board
 * games", ADR 034 decision 20), additive and nullable: the room's two seats
 * for the next board game (`rooms.setup_white_id`, `setup_black_id`,
 * written by `ChooseRoomGame` and `StartRoomGame`), a board game's seats and
 * clock on its `room_games` row, copied at the start, and `room_acts`, the
 * only facts of a match (`RecordRoomAct`). A race has none of the four new
 * `room_games` columns and a board game all four. On SQLite an added
 * foreign key rebuilds the table with the foreign keys switched off
 * (migrations run outside a transaction there), so no row is lost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->foreignUlid('setup_white_id')->nullable()->constrained('room_players')->nullOnDelete();
            $table->foreignUlid('setup_black_id')->nullable()->constrained('room_players')->nullOnDelete();
        });

        Schema::table('room_games', function (Blueprint $table) {
            $table->foreignUlid('white_player_id')->nullable()->constrained('room_players')->cascadeOnDelete();
            $table->foreignUlid('black_player_id')->nullable()->constrained('room_players')->cascadeOnDelete();
            $table->unsignedInteger('clock_ms')->nullable();
            $table->unsignedInteger('increment_ms')->nullable();
        });

        Schema::create('room_acts', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id')->nullable()->unique();
            $table->foreignUlid('room_game_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_player_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->unsignedInteger('ply');
            $table->json('path')->nullable();
            $table->bigInteger('acted_ms');

            $table->unique(['room_game_id', 'kind', 'ply', 'room_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_acts');

        Schema::table('room_games', function (Blueprint $table) {
            $table->dropConstrainedForeignId('white_player_id');
            $table->dropConstrainedForeignId('black_player_id');
            $table->dropColumn(['clock_ms', 'increment_ms']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropConstrainedForeignId('setup_white_id');
            $table->dropConstrainedForeignId('setup_black_id');
        });
    }
};
