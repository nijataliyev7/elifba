<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

/**
 * Where a signed-in account lands (docs/architecture.md §1 "Accounts and
 * roles", ADR 010 decision 5): the role choice without a role, otherwise
 * the home of its area (`User::area()`). Fortify's `home`, the guest pages'
 * redirect, `/dashboard`, the Google callback and the logo all lead here.
 */
final class StartController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): RedirectResponse
    {
        $area = $user->area();

        return $area === null ? to_route('role') : to_route($area->homeRoute());
    }
}
