<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The rest of a game's setup (docs/architecture.md §1, §2 "Game setup"), so
 * the board can rebuild its rounds with another count before the first
 * answer (`ChangeRoundCount`) and the end screen can start the same game
 * again (`ReplayGame`): the letters asked for (null: every learned letter)
 * and whether only the owner's own words play. Games stored before this
 * have null and false, and replay with those.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->json('letters')->nullable()->after('letter_case');
            $table->boolean('own_words')->default(false)->after('letters');
        });
    }

    public function down(): void
    {
        Schema::table('game_sessions', function (Blueprint $table) {
            $table->dropColumn(['letters', 'own_words']);
        });
    }
};
