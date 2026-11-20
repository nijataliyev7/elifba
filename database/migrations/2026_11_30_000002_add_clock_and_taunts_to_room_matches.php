<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A board match's clock and its taunts (docs/architecture.md §12 "Board
 * games", ADR 034 decisions 9–11). Each side has its own time
 * (`white_left_ms`, `black_left_ms`, `elifba.rooms.checkers.clock_ms` at
 * the start) that runs only on its turn, from `turn_since_ms`; a side
 * whose time runs out has lost — derived on every read (`RoomMatch::
 * settled()`), never by a job. A taunt in a match is a ready-made key
 * thrown at the opponent, free and limited by the cooldown; it holds no
 * text, and its target is emptied with the others' after a day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_matches', function (Blueprint $table) {
            $table->unsignedInteger('white_left_ms')->default(0);
            $table->unsignedInteger('black_left_ms')->default(0);
            $table->bigInteger('turn_since_ms')->default(0);
        });

        Schema::create('room_match_taunts', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('event_id')->unique();
            $table->foreignUlid('room_match_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_player_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('target_player_id')->nullable()->constrained('room_players')->nullOnDelete();
            $table->string('taunt', 32);
            $table->bigInteger('sent_ms');

            $table->index(['room_match_id', 'room_player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_match_taunts');

        Schema::table('room_matches', function (Blueprint $table) {
            $table->dropColumn(['white_left_ms', 'black_left_ms', 'turn_since_ms']);
        });
    }
};
