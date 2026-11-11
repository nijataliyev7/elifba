<?php

namespace App\Domain\Family\Models;

use App\Domain\Family\Policies\ChildPolicy;
use App\Models\Concerns\BelongsToOwner;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\ChildFactory;
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
 * A parent's own child, without a class (docs/architecture.md §1, §7
 * "Parents and their children", ADR 011, ADR 013): a first name and an
 * avatar, plus what the games need — the parent's order and the learned
 * letters. Nothing else about a child is stored, and nothing joins a child
 * to a teacher's student. The display name never goes into a log, an
 * exception, a broadcast or a URL; log the id. `{child}` binds only the
 * current account's children (`BelongsToOwner`). A child is deleted, never
 * archived: the child's games, rounds and answers go with the row.
 *
 * @property string $id
 * @property int $user_id the parent
 * @property string $display_name a first name, never a surname or its initial
 * @property string $avatar key from content/avatars.json
 * @property list<string>|null $learned_letters lowercase, alphabet order; null = all 32
 * @property int $position the parent's order; lists are never ordered by stars
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User $parent
 */
#[UseFactory(ChildFactory::class)]
#[UsePolicy(ChildPolicy::class)]
#[Fillable(['display_name', 'avatar', 'learned_letters', 'position'])]
class Child extends Model
{
    /** @use HasFactory<ChildFactory> */
    use BelongsToOwner, HasFactory, HasUlids;

    /**
     * @var string
     */
    protected $table = 'children';

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public static function whereOwner(Builder $query, User $owner): Builder
    {
        return $query->where($query->qualifyColumn('user_id'), $owner->id);
    }

    public function isOwnedBy(User $owner): bool
    {
        return $this->user_id === $owner->id;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'learned_letters' => 'array',
            'position' => 'integer',
        ];
    }

    /**
     * The children of one parent.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $parent): void
    {
        $query->where('user_id', $parent->id);
    }

    /**
     * The parent's own order.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
