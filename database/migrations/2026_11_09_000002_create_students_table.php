<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A student is a display name and an avatar in one class, nothing more
 * (docs/architecture.md §1, §7). Do not add fields about children.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('students', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignUlid('school_class_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 40);
            $table->string('avatar', 32);
            $table->integer('position');
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();

            $table->index(['school_class_id', 'archived_at', 'position']);
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('students');
    }
};
