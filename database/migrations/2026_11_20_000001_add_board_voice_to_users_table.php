<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The account's choice of the board's voice in Settings → Oyun
 * (docs/architecture.md §3 "Voice", ADR 015): `{"prompt": bool, "praise":
 * bool}`; null, the default, says both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('board_voice')->nullable()->after('board_sounds');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('board_voice');
        });
    }
};
