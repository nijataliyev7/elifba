<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A parent's own children, without a class (docs/architecture.md §1, §7
 * "Parents and their children", ADR 011, ADR 013): a first name, an avatar,
 * the learned letters and the parent's order — nothing else about a child.
 * A child is deleted, never archived. The name is unique among one parent's
 * children with Azerbaijani casing, which `AddChild` and `UpdateChild` check
 * in PHP under a lock on the parent's `users` row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('children', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('display_name', 40);
            $table->string('avatar', 32);
            $table->json('learned_letters')->nullable(); // null = all 32
            $table->unsignedInteger('position');
            $table->timestamps();

            $table->index(['user_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('children');
    }
};
