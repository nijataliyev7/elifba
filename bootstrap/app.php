<?php

use App\Http\Middleware\EnsureRoleChosen;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\RoleMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Behind Caddy in docker-compose.yml (§10). Which proxies: the
        // `trustedproxy.proxies` config (TRUSTED_PROXIES), none by default.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        // A signed-in account opening a guest page (login, register) lands
        // where login does, by role: there is no dashboard.
        $middleware->redirectUsersTo(fn (): string => route('start'));

        // The areas of docs/architecture.md §1 "Accounts and roles" (ADR 010):
        // `role` is the package's, with an App\Enums\Role name or two.
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'role.chosen' => EnsureRoleChosen::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Children's names and the pasted list (which holds surnames) never go
        // into the session as old input when validation fails (§7).
        $exceptions->dontFlash(['text', 'syllables', 'word', 'names', 'display_name']);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // An area the account lacks is 403; a typed or bookmarked page leads
        // home instead of to an error (ADR 010 decision 3).
        $exceptions->render(function (UnauthorizedException $exception, Request $request) {
            if ($request->isMethod('GET') && ! $request->expectsJson() && $request->user() !== null) {
                return to_route('start');
            }

            return null;
        });
    })->create();
