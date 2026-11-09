<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * A board on a shared school computer, claimed from the teacher's phone
 * (docs/architecture.md §1, §4). It authenticates with a bearer made from a
 * secret only its browser tab holds; the row keeps the secret's hash. The
 * pairing code shares one code space with game_sessions.join_code
 * (`PairingCode`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guest_boards', function (Blueprint $table) {
            $table->ulid('id')->primary();
            $table->char('code', 6)->nullable()->unique();
            $table->timestamp('code_expires_at')->nullable();
            $table->string('secret_hash');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('game_session_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index('expires_at');
            $table->index(['user_id', 'game_session_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guest_boards');
    }
};
