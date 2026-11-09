<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A teacher's class (docs/architecture.md §1). `class` is a reserved word in
 * PHP, so the model is SchoolClass and the table `school_classes`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('school_classes', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 40);
            $table->unsignedTinyInteger('grade')->nullable(); // 1–4
            $table->json('learned_letters')->nullable(); // lowercase, alphabet order; null = all 32
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'archived_at']);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('school_classes');
    }
};
