<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Bought letters (docs/architecture.md §1 `room_letters`, §12 "«Anaqram»:
 * a clue, a shuffle and a bought letter (ADR 037)", ADR 037 decision 12):
 * a row for every letter of an anagram's word a player bought in a round —
 * the round, the buyer, the letter's place in the drawn word and the
 * server's moment. Never a letter, a text or a name: the tile is the
 * round's solution at that place.
 *
 * Unique by round, buyer and place, so a double tap or a second device
 * never buys a letter twice; unique by `event_id`, so a buy sent twice is
 * stored once. The rows go with their round and with the buyer's row
 * (cascade), and so with the room.
 *
 * Additive: rooms are live in production, and a round built before ADR 037
 * sells no letters.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('room_letters', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->ulid('event_id')->unique();
            $table->foreignUlid('room_round_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('room_player_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->bigInteger('bought_ms');

            $table->unique(['room_round_id', 'room_player_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_letters');
    }
};
