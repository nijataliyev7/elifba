<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Domino in a room (docs/architecture.md §1 "Tables", §12 "Domino", ADR 038
 * decision 42), additive and nullable: the table and the seats of the
 * room's next domino game (`rooms.setup_table`, `setup_seats`, written only
 * by `ChooseRoomGame`), and a domino game's table, seats and turn's time on
 * its `room_games` row, copied from the choice at the start. A seat is a
 * `room_players.id` or null — the computer's — in the order of the turns.
 * `room_acts` does not change: its `kind` takes `play`, `yield` and `back`,
 * its `path` a tile and an end. No column holds a hand, a line, a
 * boneyard, a turn or a score: they are replayed from the acts and the
 * seed (`DominoGame`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->string('setup_table', 8)->nullable();
            $table->json('setup_seats')->nullable();
        });

        Schema::table('room_games', function (Blueprint $table) {
            $table->string('table', 8)->nullable();
            $table->json('seats')->nullable();
            $table->unsignedInteger('turn_ms')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_games', function (Blueprint $table) {
            $table->dropColumn(['table', 'seats', 'turn_ms']);
        });

        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn(['setup_table', 'setup_seats']);
        });
    }
};
