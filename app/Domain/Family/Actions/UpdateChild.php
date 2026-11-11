<?php

namespace App\Domain\Family\Actions;

use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Family\Data\ChildData;
use App\Domain\Family\Models\Child;
use App\Domain\Family\Support\FamilyLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes a child's name or avatar (no avatar sent keeps the current one).
 * The name stays unique among the parent's other children, compared with
 * Azerbaijani casing, under the parent's lock.
 */
final readonly class UpdateChild
{
    /**
     * @throws ValidationException on `display_name` when another of the parent's children has the name
     */
    public function handle(Child $child, ChildData $data): Child
    {
        return DB::transaction(function () use ($child, $data): Child {
            FamilyLock::acquire($child->user_id);

            if (isset(FamilyLock::names($child->user_id, $child->id)[DisplayName::key($data->display_name)])) {
                throw ValidationException::withMessages(['display_name' => __('children.errors.taken')]);
            }

            $child->update([
                'display_name' => $data->display_name,
                'avatar' => $data->avatar ?? $child->avatar,
            ]);

            return $child;
        });
    }
}
