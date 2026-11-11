<?php

namespace App\Http\Controllers\Family;

use App\Domain\Family\Actions\ReorderChildren;
use App\Domain\Family\Data\ReorderChildrenData;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saves the parent's order of their children. The ids come in the body,
 * not the route, so `ReorderChildren` checks they are exactly this
 * parent's.
 */
final class ChildOrderController extends Controller
{
    public function __invoke(Request $request, #[CurrentUser] User $parent, ReorderChildren $reorderChildren): RedirectResponse
    {
        $reorderChildren->handle($parent, ReorderChildrenData::validate($request->all()));

        return to_route('children.index');
    }
}
