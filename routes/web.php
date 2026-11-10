<?php

use App\Http\Controllers\CreditsController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PrivacyController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// The az/en switch: guests keep it in the session, teachers on their account.
Route::put('locale', LocaleController::class)
    ->middleware('throttle:30,1')
    ->name('locale.update');

// The privacy notice and the note for parents, public (§7).
Route::get('privacy', PrivacyController::class)->name('privacy');

// Who made the pictures, sounds and fonts, and their licenses, public (§8).
Route::get('credits', CreditsController::class)->name('credits');

// There is no dashboard: a teacher lands on the classes (Fortify's `home`).
// Old bookmarks of the starter kit's /dashboard follow.
Route::redirect('dashboard', '/classes');

require __DIR__.'/classroom.php';
require __DIR__.'/play.php';
require __DIR__.'/rankings.php';
require __DIR__.'/settings.php';
require __DIR__.'/words.php';
