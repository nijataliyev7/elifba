<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * What a class or a child last chose on «Yeni oyun» (docs/architecture.md
 * §1, §2 "Remembered setup"): the mode, the level and the letter case per
 * unit, the letters asked for and «Yalnız mənim sözlərim». At most one row
 * for each class and each child, exactly one of the two set; it goes with
 * its class or child. Nothing about a child (ADR 013): a mode, level keys,
 * cases, letters and a flag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_choices', function (Blueprint $table) {
            $table->id();
            $table->foreignUlid('school_class_id')->nullable()->unique()->constrained()->cascadeOnDelete();
            $table->foreignUlid('child_id')->nullable()->unique()->constrained('children')->cascadeOnDelete();
            $table->string('mode', 16);
            $table->json('levels')->nullable(); // unit → level key
            $table->json('letter_cases')->nullable(); // unit → upper | lower
            $table->json('letters')->nullable(); // null = every learned letter
            $table->boolean('own_words')->nullable(); // null = never chosen
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('setup_choices');
    }
};
