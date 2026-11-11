<?php

namespace App\Domain\Family\Actions;

use App\Domain\Family\Data\ReorderChildrenData;
use App\Domain\Family\Models\Child;
use App\Domain\Family\Support\FamilyLock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves the parent's order of their children. The ids must be exactly the
 * parent's children, each once: they are not route-bound, so this check is
 * what keeps another parent's children out.
 */
final readonly class ReorderChildren
{
    /**
     * @throws ValidationException on `child_ids` when the ids are not exactly the parent's children
     */
    public function handle(User $parent, ReorderChildrenData $data): void
    {
        DB::transaction(function () use ($parent, $data): void {
            FamilyLock::acquire($parent->id);

            /** @var array<string, int> $current id => position */
            $current = Child::query()->where('user_id', $parent->id)->pluck('position', 'id')->all();
            $given = $data->child_ids;

            if (count($given) !== count($current)
                || count(array_unique($given)) !== count($given)
                || array_diff($given, array_keys($current)) !== []) {
                throw ValidationException::withMessages(['child_ids' => __('children.errors.order_mismatch')]);
            }

            foreach ($given as $index => $id) {
                if ($current[$id] !== $index + 1) {
                    // The order is not an edit of the child: `updated_at` stays.
                    Child::query()->whereKey($id)->toBase()->update(['position' => $index + 1]);
                }
            }
        });
    }
}
