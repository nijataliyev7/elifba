<?php

use App\Http\Controllers\Auth\GoogleController;
use App\Http\Controllers\Auth\GoogleRegistrationController;
use App\Http\Middleware\EnsureGoogleSignInIsEnabled;
use Illuminate\Support\Facades\Route;

// Google sign-in for teachers (docs/architecture.md §7 "Google sign-in",
// ADR 008). The routes always exist, so Wayfinder generates them everywhere;
// without GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET they answer 404.
Route::middleware([EnsureGoogleSignInIsEnabled::class, 'throttle:google'])
    ->prefix('auth/google')
    ->name('google.')
    ->group(function () {
        Route::middleware('guest')->group(function () {
            Route::get('redirect', [GoogleController::class, 'redirect'])->name('redirect');
            Route::get('register', [GoogleRegistrationController::class, 'create'])->name('register');
            Route::post('register', [GoogleRegistrationController::class, 'store'])->name('register.store');
            Route::delete('register', [GoogleRegistrationController::class, 'destroy'])->name('register.destroy');
        });

        Route::get('confirm', [GoogleController::class, 'confirm'])->middleware('auth')->name('confirm');

        // Both round trips come back here; the session says which one it was.
        Route::get('callback', [GoogleController::class, 'callback'])->name('callback');
    });
