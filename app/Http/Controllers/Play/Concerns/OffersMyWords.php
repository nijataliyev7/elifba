<?php

namespace App\Http\Controllers\Play\Concerns;

use App\Domain\Content\Support\CustomWordList;
use App\Enums\Role;
use App\Models\User;
use Inertia\Inertia;
use Inertia\OptionalProp;

/**
 * «Sözlərim» on a teacher's game catalog (docs/frontend.md rule 13,
 * docs/architecture.md §2 "Game setup"): the teacher's words and limits as
 * `myWords`, an optional prop sent only when a partial reload asks for it —
 * when the dialog opens, and with the catalog's options after a word
 * changes. An account without the teacher role gets no prop at all, so a
 * parent never sees it.
 */
trait OffersMyWords
{
    /**
     * @return array{myWords?: OptionalProp}
     */
    protected function myWords(User $owner): array
    {
        if (! in_array(Role::Teacher, $owner->roleList(), true)) {
            return [];
        }

        return ['myWords' => Inertia::optional(static fn (CustomWordList $list): array => $list->mine($owner))];
    }
}
