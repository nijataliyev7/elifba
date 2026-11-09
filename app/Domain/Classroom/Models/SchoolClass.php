<?php

namespace App\Domain\Classroom\Models;

use App\Domain\Classroom\Policies\SchoolClassPolicy;
use App\Models\Concerns\BelongsToTeacher;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\SchoolClassFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A teacher's class, e.g. "1A" (docs/architecture.md §1). Archived classes
 * are hidden from pickers and deleted after `elifba.retention_days`.
 * `{class}` binds only the current teacher's classes (`BelongsToTeacher`).
 *
 * @property string $id
 * @property int $user_id
 * @property string $name
 * @property int|null $grade
 * @property list<string>|null $learned_letters lowercase, alphabet order; null = all 32
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $teacher
 * @property-read Collection<int, Student> $students
 */
#[UseFactory(SchoolClassFactory::class)]
#[UsePolicy(SchoolClassPolicy::class)]
#[Fillable(['name', 'grade', 'learned_letters', 'archived_at'])]
class SchoolClass extends Model
{
    /** @use HasFactory<SchoolClassFactory> */
    use BelongsToTeacher, HasFactory, HasUlids;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function whereTeacher(Builder $query, User $teacher): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $teacher->id);
    }

    public function isOwnedBy(User $teacher): bool
    {
        return $this->user_id === $teacher->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return HasMany<Student, $this>
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grade' => 'integer',
            'learned_letters' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Classes the teacher owns.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $teacher): void
    {
        $query->where('user_id', $teacher->id);
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function archived(Builder $query): void
    {
        $query->whereNotNull('archived_at');
    }
}
