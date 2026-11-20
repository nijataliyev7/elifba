<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Board games in a room (docs/architecture.md §12 "Board games", ADR 034):
 * the board game a room was opened for (`rooms.board`, null for the round
 * games), a match between two of the room's players and its moves. They
 * reference a room and its player rows only — never a class, a student, a
 * child or a game of `Play` — and no column holds a name or anything a
 * person typed: a move is a list of square numbers.
 *
 * Only facts are stored: who plays which side, the start, each move, and
 * how the match ended. The position and the side to move are kept with the
 * match (`board`, `turn`, `ply`, `quiet_plies`) because every move reads
 * them under the room's lock; `room_moves` is the record they follow from.
 * Moments are Unix milliseconds in `bigint` columns, as in a round game.
 *
 * Everything goes with its room (cascade).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('board', 16)->nullable();
        });

        Schema::create('room_matches', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position');
            $table->string('board_game', 16);
            $table->foreignUlid('white_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->foreignUlid('black_player_id')->constrained('room_players')->cascadeOnDelete();
            $table->char('board', 64);
            $table->char('turn', 1);
            $table->unsignedInteger('ply')->default(0);
            $table->unsignedInteger('quiet_plies')->default(0);
            $table->json('last_path')->nullable();
            $table->bigInteger('started_ms');
            $table->bigInteger('ended_ms')->nullable();
            $table->string('result', 8)->nullable();
            $table->string('ending', 16)->nullable();
            $table->timestamp('created_at');

            $table->unique(['room_id', 'position']);
        });

        Schema::create('room_moves', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->foreignUlid('room_match_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('ply');
            $table->foreignUlid('room_player_id')->constrained()->cascadeOnDelete();
            $table->json('path');
            $table->json('captured');
            $table->bigInteger('created_ms');

            $table->unique(['room_match_id', 'ply']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_moves');
        Schema::dropIfExists('room_matches');

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('board');
        });
    }
};
