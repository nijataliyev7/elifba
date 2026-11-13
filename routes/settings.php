<?php

use App\Http\Controllers\Settings\PlaySettingsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\RolesController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

/*
 * The account's own pages (docs/architecture.md §1 "Accounts and roles",
 * ADR 010): no role is named. The profile stays reachable without a role,
 * so an account can always be deleted; the other settings wait for the
 * role choice (`role.chosen`).
 */
Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'role.chosen', 'verified'])->group(function () {
    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->middleware(RequirePassword::class)
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    // «Başqa cihazlardan çıx» (docs/architecture.md §7 "Remembered login", ADR 017).
    Route::delete('settings/other-devices', [SecurityController::class, 'logOutOtherDevices'])
        ->middleware([RequirePassword::class, 'throttle:6,1'])
        ->name('other-devices.destroy');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/play', [PlaySettingsController::class, 'edit'])->name('play-settings.edit');
    Route::put('settings/play', [PlaySettingsController::class, 'update'])->name('play-settings.update');
    Route::put('settings/play/sounds', [PlaySettingsController::class, 'updateSounds'])->name('play-settings.sounds.update');
    Route::put('settings/play/voice', [PlaySettingsController::class, 'updateVoice'])->name('play-settings.voice.update');

    // The second role, with its sentence; a role is never removed.
    Route::get('settings/roles', [RolesController::class, 'edit'])->name('roles.edit');
    Route::post('settings/roles', [RolesController::class, 'store'])->name('roles.store');
});
