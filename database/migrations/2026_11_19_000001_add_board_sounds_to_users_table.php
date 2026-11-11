<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The sounds the account chose for its boards in Settings → Oyun
 * (docs/architecture.md §3 "Sounds"): a key of `content/sounds.json` per
 * moment; null, or a moment left out, plays the pack's own sound.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('board_sounds')->nullable()->after('phone_remote');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('board_sounds');
        });
    }
};
