<?php

use App\Enums\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/*
 * Account roles (docs/architecture.md §1 "Accounts and roles", §7
 * "Agreements", ADR 010, ADR 013): the area an account with both roles is
 * in, the moment each role's sentence was accepted, the two `roles` rows,
 * and every existing account a teacher. Existing accounts accepted the
 * school sentence at registration, so that moment becomes their teacher
 * agreement. Query builder only.
 */
return new class extends Migration
{
    /** The guard the roles belong to: the accounts' session guard. */
    private const GUARD = 'web';

    /** `model_has_roles.model_type`: the morph alias of `User` (AppServiceProvider). */
    private const USER_MORPH = 'user';

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('area')->nullable()->after('terms_accepted_at');
            $table->timestamp('teacher_agreed_at')->nullable()->after('area');
            $table->timestamp('parent_agreed_at')->nullable()->after('teacher_agreed_at');
        });

        $now = now();

        // Named one by one, not from Role::cases(): a later role gets its own migration.
        DB::table('roles')->insert(array_map(static fn (Role $role): array => [
            'name' => $role->value,
            'guard_name' => self::GUARD,
            'created_at' => $now,
            'updated_at' => $now,
        ], [Role::Teacher, Role::Parent]));

        $teacher = DB::table('roles')
            ->where('name', Role::Teacher->value)
            ->where('guard_name', self::GUARD)
            ->value('id');

        DB::table('users')->select('id')->chunkById(500, static function (Collection $users) use ($teacher): void {
            DB::table('model_has_roles')->insert($users->map(static fn (object $user): array => [
                'role_id' => $teacher,
                'model_type' => self::USER_MORPH,
                'model_id' => $user->id,
            ])->all());
        });

        // A column copied to a column: plain SQL on every engine.
        DB::table('users')->update([
            'teacher_agreed_at' => DB::raw('terms_accepted_at'),
            'area' => Role::Teacher->value,
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $roles = DB::table('roles')
            ->whereIn('name', [Role::Teacher->value, Role::Parent->value])
            ->where('guard_name', self::GUARD)
            ->pluck('id');

        DB::table('model_has_roles')->whereIn('role_id', $roles)->delete();
        DB::table('roles')->whereIn('id', $roles)->delete();

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['area', 'teacher_agreed_at', 'parent_agreed_at']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
