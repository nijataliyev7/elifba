<?php

namespace App\Domain\Classroom\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Teacher isolation, first layer (docs/architecture.md §7): a route parameter
 * bound to this model resolves only rows the current teacher owns. Another
 * teacher's id — or any id for a guest — finds nothing, so the router answers
 * 404 before controller code runs. Policies are the second layer.
 *
 * Nested parameters (`{class}/students/{student}`) use `scopeBindings()`, so
 * the child is looked up through the already-isolated parent.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToTeacher
{
    /**
     * Restricts a query to the rows the teacher owns.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    abstract public static function whereTeacher(Builder $query, User $teacher): Builder;

    /**
     * Whether the teacher owns this row.
     */
    abstract public function isOwnedBy(User $teacher): bool;

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $teacher = Auth::user();

        if (! $teacher instanceof User) {
            return null;
        }

        // A malformed id simply matches nothing, so it is a 404 like any other.
        return static::whereTeacher($this->newQuery(), $teacher)
            ->where($this->qualifyColumn($field ?? $this->getRouteKeyName()), $value)
            ->first();
    }
}
