<?php

use App\Http\Controllers\AreaController;
use App\Http\Controllers\CreditsController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ManifestController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\StartController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

// The landing page: what Elifba is, for a teacher who opens a shared link
// (docs/frontend.md rule 10).
Route::get('/', WelcomeController::class)->name('home');

// The az/en switch: guests keep it in the session, teachers on their account.
Route::put('locale', LocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.update');

// The privacy notice and the note for parents, public (§7).
Route::get('privacy', PrivacyController::class)->name('privacy');

// Who made the pictures, sounds and fonts, and their licenses, public (§8).
Route::get('credits', CreditsController::class)->name('credits');

// The web app manifest, for installing Elifba from Chrome (§10). Chrome
// fetches it without cookies: no session, no locale but APP_LOCALE.
Route::get('manifest.webmanifest', ManifestController::class)
    ->withoutMiddleware('web')
    ->middleware('cache.headers:public;max_age=86400;etag')
    ->name('manifest');

/*
 * Roles (docs/architecture.md §1 "Accounts and roles", ADR 010). The role
 * choice needs a session only, so it comes before e-mail confirmation;
 * `/start` lands by role; the switch moves an account with both roles to
 * its other area.
 */
Route::middleware('auth')->group(function () {
    Route::get('role', [RoleController::class, 'create'])->name('role');
    Route::post('role', [RoleController::class, 'store'])->name('role.store');

    Route::get('start', StartController::class)->name('start');

    Route::put('area', AreaController::class)->name('area.update');
});

// There is no dashboard: an account lands by role (Fortify's `home`).
// Old bookmarks of the starter kit's /dashboard follow.
Route::redirect('dashboard', '/start');

require __DIR__.'/auth.php';
require __DIR__.'/classroom.php';
require __DIR__.'/family.php';
require __DIR__.'/play.php';
require __DIR__.'/rankings.php';
require __DIR__.'/settings.php';
require __DIR__.'/words.php';
