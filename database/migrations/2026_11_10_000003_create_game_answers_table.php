<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Every hit the board synced, correct or not (docs/architecture.md §1, §4).
 * `event_id` is made by the device that produced the hit and is unique, so
 * a retried or doubled sync stores a hit once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_answers', function (Blueprint $table) {
            $table->id();
            $table->ulid('event_id')->unique();
            $table->foreignUlid('game_round_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('student_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_id', 8);
            $table->string('content_key');
            $table->boolean('correct');
            $table->string('source');
            $table->unsignedInteger('board_epoch');
            $table->timestamp('client_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['game_round_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_answers');
    }
};
