<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A built-in word a teacher has hidden from their own games
 * (docs/architecture.md §1, §3 "Teacher words", ADR 007). Other teachers
 * still play it; showing it again deletes the row. A row whose word a later
 * pack version removed does nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('hidden_words', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pack');
            $table->string('word_key', 40);
            $table->timestamp('created_at')->nullable();

            $table->unique(['user_id', 'pack', 'word_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hidden_words');
    }
};
