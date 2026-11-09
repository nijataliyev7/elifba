<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One round of a game: the snapshot the board and the phone show
 * (`payload`, RoundData without id/index/total) and its result
 * (docs/architecture.md §1, §2, §5). Points, `played_on` (the Baku date of
 * `solved_at`) and `week_start` (its Monday) are set when the round is
 * solved; rankings read only these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_rounds', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('game_session_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('school_class_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position'); // 0-based
            $table->json('payload');
            $table->json('progress'); // item ids hit correctly so far, in order
            $table->unsignedInteger('misses')->default(0);
            $table->timestamp('solved_at')->nullable();
            $table->timestamp('skipped_at')->nullable();
            $table->foreignUlid('student_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedTinyInteger('points')->default(0);
            $table->date('played_on')->nullable();
            $table->date('week_start')->nullable();

            $table->unique(['game_session_id', 'position']);
            $table->index(['school_class_id', 'played_on']);
            $table->index(['school_class_id', 'week_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_rounds');
    }
};
