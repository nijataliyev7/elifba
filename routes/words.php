<?php

use App\Enums\Role;
use App\Http\Controllers\Content\HiddenWordController;
use App\Http\Controllers\Content\WordController;
use Illuminate\Support\Facades\Route;

/*
 * The teacher's words (docs/architecture.md §3 "Teacher words", ADR 007).
 * A teacher word may be a child's name, so no word is ever in a URL:
 * `{word}` is a teacher word's ULID, bound only among the current
 * teacher's words (`BelongsToTeacher`, 404 before any controller code) and
 * checked by `CustomWordPolicy` (`->can()`); hiding and showing a built-in
 * word carry the pack and the word in the body. The `{word}` routes are in
 * tests/Feature/Classroom/TeacherIsolationTest.php. The teacher area
 * (§1 "Accounts and roles").
 */
Route::middleware(['auth', 'role.chosen', 'verified', Role::middleware(Role::Teacher)])->group(function () {
    // ?pack={key}: the theme pack shown; the first one otherwise.
    Route::get('words', [WordController::class, 'index'])->name('words.index');
    Route::post('words', [WordController::class, 'store'])->name('words.store');

    Route::post('words/hidden', [HiddenWordController::class, 'store'])->name('words.hidden.store');
    Route::delete('words/hidden', [HiddenWordController::class, 'destroy'])->name('words.hidden.destroy');

    Route::patch('words/{word}', [WordController::class, 'update'])
        ->whereUlid('word')
        ->can('update', 'word')
        ->name('words.update');
    Route::delete('words/{word}', [WordController::class, 'destroy'])
        ->whereUlid('word')
        ->can('delete', 'word')
        ->name('words.destroy');
});
