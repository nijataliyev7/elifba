<?php

namespace App\Models\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Isolation, first layer (docs/architecture.md §7): a route parameter bound
 * to this model resolves only rows the current account owns — a teacher's
 * classes, students, words and games, a parent's children and games.
 * Another account's id — or any id for a guest — finds nothing, so the
 * router answers 404 before controller code runs. Policies are the second
 * layer.
 *
 * Nested parameters (`{class}/students/{student}`) use `scopeBindings()`, so
 * the child is looked up through the already-isolated parent.
 *
 * @phpstan-require-extends Model
 */
trait BelongsToOwner
{
    /**
     * Restricts a query to the rows the account owns.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    abstract public static function whereOwner(Builder $query, User $owner): Builder;

    /**
     * Whether the account owns this row.
     */
    abstract public function isOwnedBy(User $owner): bool;

    /**
     * @param  mixed  $value
     * @param  string|null  $field
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $owner = Auth::user();

        if (! $owner instanceof User) {
            return null;
        }

        // A malformed id simply matches nothing, so it is a 404 like any other.
        return static::whereOwner($this->newQuery(), $owner)
            ->where($this->qualifyColumn($field ?? $this->getRouteKeyName()), $value)
            ->first();
    }
}
