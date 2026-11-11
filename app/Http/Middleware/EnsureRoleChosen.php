<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `role.chosen` (docs/architecture.md §1 "Accounts and roles", ADR 010
 * decision 4): an account that has not chosen a role yet reaches nothing
 * but the role choice, its profile and logging out. A page visit goes to
 * `/role`; a JSON or non-GET request gets 409 `role_required`. Anyone who
 * is not an account (a guest board's bearer) passes: its routes decide.
 */
final class EnsureRoleChosen
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->roleList() !== []) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            return to_route('role');
        }

        return response()->json(['error' => 'role_required'], Response::HTTP_CONFLICT);
    }
}
