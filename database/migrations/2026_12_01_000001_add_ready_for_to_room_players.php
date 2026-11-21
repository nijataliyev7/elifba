<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A player's «Hazıram» between games (docs/architecture.md §1
 * `room_players`, §12 "Ready for the next game", ADR 035 decision 3): the
 * position of the game the player said it for — the room's next game at
 * the tap — and no moment. Written only by `SetRoomPlayerReady` and
 * cleared by `LeaveRoom`; whether it counts is derived (`RoomReady`), so a
 * start writes nothing for it.
 *
 * Additive and nullable: rooms are live in production, and nobody has
 * said «Hazıram» before this column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->unsignedInteger('ready_for')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('room_players', function (Blueprint $table) {
            $table->dropColumn('ready_for');
        });
    }
};
