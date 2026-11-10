<?php

namespace App\Http\Controllers;

use App\Support\WebApp;
use Illuminate\Http\JsonResponse;

/**
 * The web app manifest Chrome reads to offer installing Elifba
 * (docs/architecture.md §10 "Installing Elifba"). It runs outside the `web`
 * middleware group: Chrome fetches it without cookies, and a session started
 * for every fetch would only fill the sessions table.
 */
final class ManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(
            WebApp::manifest(),
            headers: ['Content-Type' => 'application/manifest+json'],
            options: JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }
}
