<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A game: one mechanic × theme pack × level played by one class
 * (docs/architecture.md §1, §2). Its rounds are snapshots in game_rounds.
 * `status` changes only through TransitionGameSession; `board_epoch` fences
 * the board allowed to write (§4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_sessions', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('school_class_id')->constrained()->cascadeOnDelete();
            $table->string('mechanic');
            $table->string('pack');
            $table->unsignedInteger('pack_version');
            $table->string('level');
            $table->string('letter_case');
            $table->unsignedInteger('seed');
            $table->string('status');
            $table->unsignedInteger('board_epoch')->default(0);
            $table->char('join_code', 6)->nullable()->unique(); // remote pairing, A7
            $table->timestamp('join_code_expires_at')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->timestamp('last_activity_at');

            $table->index(['user_id', 'status']);
            $table->index(['status', 'last_activity_at']);
            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_sessions');
    }
};
