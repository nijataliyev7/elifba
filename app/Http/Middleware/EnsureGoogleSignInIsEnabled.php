<?php

namespace App\Http\Middleware;

use App\Support\GoogleSignIn;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Google sign-in routes are not there unless the operator configured an
 * OAuth client (ADR 008): they always exist for Wayfinder, but answer 404.
 */
final class EnsureGoogleSignInIsEnabled
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(GoogleSignIn::enabled(), 404);

        return $next($request);
    }
}
