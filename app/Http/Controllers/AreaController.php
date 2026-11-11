<?php

namespace App\Http\Controllers;

use App\Actions\Roles\SwitchArea;
use App\Data\AreaData;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The switch between the teacher and the parent area of an account with
 * both roles (docs/architecture.md §1 "Accounts and roles", ADR 010
 * decision 7): it opens the chosen area's home.
 */
final class AreaController extends Controller
{
    public function __invoke(Request $request, #[CurrentUser] User $user, SwitchArea $switchArea): RedirectResponse
    {
        $data = AreaData::validate($user, $request->all());

        $switchArea->handle($user, $data);

        return to_route($data->area->homeRoute());
    }
}
