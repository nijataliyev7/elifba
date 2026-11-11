<?php

namespace App\Domain\Classroom\Models;

use App\Domain\Classroom\Policies\StudentPolicy;
use App\Models\Concerns\BelongsToOwner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\StudentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A child in a class: a display name and an avatar, nothing more
 * (docs/architecture.md §1, §7). The display name never goes into a log, an
 * exception, a broadcast or a URL; log the id. `{student}` binds only the
 * current teacher's students (`BelongsToOwner`), and under `{class}` only
 * that class's (`scopeBindings()`).
 *
 * @property string $id
 * @property string $school_class_id
 * @property string $display_name first name; a surname initial only to tell two apart
 * @property string $avatar key from content/avatars.json
 * @property int $position order in the class
 * @property CarbonImmutable|null $archived_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read SchoolClass $schoolClass
 */
#[UseFactory(StudentFactory::class)]
#[UsePolicy(StudentPolicy::class)]
#[Fillable(['display_name', 'avatar', 'position', 'archived_at'])]
class Student extends Model
{
    /** @use HasFactory<StudentFactory> */
    use BelongsToOwner, HasFactory, HasUlids;

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function whereOwner(Builder $query, User $teacher): Builder
    {
        return $query->whereIn(
            $query->qualifyColumn('school_class_id'),
            SchoolClass::query()->select('id')->where('user_id', $teacher->id),
        );
    }

    public function isOwnedBy(User $teacher): bool
    {
        return SchoolClass::query()->whereKey($this->school_class_id)->where('user_id', $teacher->id)->exists();
    }

    /**
     * @return BelongsTo<SchoolClass, $this>
     */
    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
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

    /**
     * The class's order (turns, lists).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
