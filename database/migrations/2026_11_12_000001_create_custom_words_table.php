<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A teacher's own word in a theme pack (docs/architecture.md §1, §3
 * "Teacher words", ADR 007). It plays in all of the teacher's classes and
 * has no picture. `word_key` is `word:{lower}` with Azerbaijani casing,
 * computed in PHP, so the unique index holds one word per teacher and pack
 * case-insensitively on every engine. The text may be a child's name: it
 * never goes into a URL or a log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_words', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('pack');
            $table->string('text', 32);
            $table->string('word_key', 40);
            $table->json('syllables')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'pack', 'word_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_words');
    }
};
