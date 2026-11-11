<?php

namespace App\Models;

use App\Enums\Role;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $email_changed_at when the teacher last changed the address in their profile (ADR 008)
 * @property string|null $google_id the OpenID `sub` of the teacher's Google account (ADR 008); null = not linked
 * @property string|null $password null for a teacher who signed up with Google and has not set one (ADR 008)
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property string|null $locale interface language (az | en); null = not chosen yet
 * @property Carbon|null $terms_accepted_at when the account accepted the privacy note at registration (§7)
 * @property Role|null $area the area an account with both roles is in (ADR 010); read it through area()
 * @property Carbon|null $teacher_agreed_at when the account accepted the teacher role's sentence (§7 "Agreements")
 * @property Carbon|null $parent_agreed_at when the account accepted the parent role's sentence (§7 "Agreements")
 * @property bool $phone_remote whether the teacher board offers the phone remote (§4 "Pairing"); on by default
 * @property array<string, string>|null $board_sounds the sounds chosen for the account's boards, a `content/sounds.json` key per moment (§3 "Sounds"); null = the packs' own
 * @property array{prompt?: bool, praise?: bool}|null $board_voice what the account's boards say (§3 "Voice", ADR 015); null = the prompt and the praise
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'locale'])]
// The roles and the area reach pages as `auth.roles` and `auth.area`, not inside `auth.user` (ADR 010).
#[Hidden(['password', 'google_id', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'roles', 'permissions', 'area', 'teacher_agreed_at', 'parent_agreed_at', 'board_sounds', 'board_voice'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * The column defaults, so a teacher not yet read back from the database
     * has them too.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'phone_remote' => true,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_changed_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'terms_accepted_at' => 'datetime',
            'phone_remote' => 'boolean',
            'board_sounds' => 'array',
            'board_voice' => 'array',
            'area' => Role::class,
            'teacher_agreed_at' => 'datetime',
            'parent_agreed_at' => 'datetime',
        ];
    }

    /**
     * The roles the account holds, in the enum's order (ADR 010).
     *
     * @return list<Role>
     */
    public function roleList(): array
    {
        $names = $this->getRoleNames();

        return array_values(array_filter(
            Role::cases(),
            static fn (Role $role): bool => $names->contains($role->value),
        ));
    }

    /**
     * The area the account is in (ADR 010 decision 7): its one role, or with
     * both the one it chose (`users.area`); null before a role is chosen.
     * It decides where `/start` lands and which navigation the shared and
     * account pages show; a page inside an area shows that area's.
     */
    public function area(): ?Role
    {
        $roles = $this->roleList();

        if (count($roles) > 1 && $this->area !== null && in_array($this->area, $roles, true)) {
            return $this->area;
        }

        return $roles[0] ?? null;
    }
}
