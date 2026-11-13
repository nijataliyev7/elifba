<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\SessionGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Laravel\Fortify\Contracts\LogoutResponse;

/**
 * «Çıxış» ends this browser's login only (docs/architecture.md §7
 * "Remembered login", ADR 017 decision 8). It replaces Fortify's logout,
 * whose `logout()` cycles the remember token and so signs every remembered
 * device of the account out: one «Çıxış» on a classroom computer would end
 * the login on the teacher's phone. Ending the others is «Başqa cihazlardan
 * çıx».
 */
final class LogoutController extends Controller
{
    public function __invoke(Request $request): LogoutResponse
    {
        $guard = Auth::guard(Config::string('fortify.guard'));

        // Forgets this browser's recaller cookie; the token stays.
        if ($guard instanceof SessionGuard) {
            $guard->logoutCurrentDevice();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return app(LogoutResponse::class);
    }
}
