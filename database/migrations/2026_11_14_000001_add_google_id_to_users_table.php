<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Google sign-in (docs/decisions/008-google-sign-in.md): the teacher's Google
 * account id (the OpenID `sub`); no password for a teacher who signed up with
 * Google until they set one; and when the teacher last changed their address,
 * so Google sign-in never takes over an account whose new address is not
 * confirmed yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('google_id')->nullable()->unique()->after('email');
            $table->string('password')->nullable()->change();
            $table->timestamp('email_changed_at')->nullable()->after('email_verified_at');
        });
    }

    /** Refused by the database while a teacher has no password: they set one first. */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['google_id']);
            $table->dropColumn(['google_id', 'email_changed_at']);
            $table->string('password')->nullable(false)->change();
        });
    }
};
