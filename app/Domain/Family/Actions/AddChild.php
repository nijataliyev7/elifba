<?php

namespace App\Domain\Family\Actions;

use App\Domain\Classroom\Support\DisplayName;
use App\Domain\Content\Support\Avatars;
use App\Domain\Family\Data\ChildData;
use App\Domain\Family\Models\Child;
use App\Domain\Family\Support\FamilyLock;
use App\Models\User;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Adds a parent's child (docs/architecture.md §1 `children`, §7 "Parents and
 * their children", ADR 011, ADR 013). Under the parent's lock: at most
 * `elifba.children.max` children, and a name the parent does not already
 * use, compared with Azerbaijani casing ("İlkin" = "ilkin"). Without an
 * avatar the child gets the first of `content/avatars.json` not yet used
 * among the parent's children (the least used once all are). The child
 * comes last in the parent's order and has learned every letter.
 */
final readonly class AddChild
{
    /**
     * @throws ValidationException on `display_name` when the parent has the name or the most children
     */
    public function handle(User $parent, ChildData $data): Child
    {
        return DB::transaction(function () use ($parent, $data): Child {
            FamilyLock::acquire($parent->id);

            $max = Config::integer('elifba.children.max');
            $children = Child::query()->where('user_id', $parent->id)->get(['avatar', 'position']);

            if ($children->count() >= $max) {
                throw ValidationException::withMessages(['display_name' => __('children.errors.too_many', ['max' => $max])]);
            }

            if (isset(FamilyLock::names($parent->id)[DisplayName::key($data->display_name)])) {
                throw ValidationException::withMessages(['display_name' => __('children.errors.taken')]);
            }

            $usage = array_fill_keys(Avatars::keys(), 0);

            foreach ($children as $child) {
                if (isset($usage[$child->avatar])) {
                    $usage[$child->avatar]++;
                }
            }

            $child = new Child([
                'display_name' => $data->display_name,
                'avatar' => $data->avatar ?? (string) array_search(min($usage), $usage, true),
                'learned_letters' => null,
                'position' => (int) $children->max('position') + 1,
            ]);
            $child->user_id = $parent->id;
            $child->save();

            return $child;
        });
    }
}
