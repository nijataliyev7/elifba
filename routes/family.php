<?php

use App\Enums\Role;
use App\Http\Controllers\Family\ChildController;
use App\Http\Controllers\Family\ChildLettersController;
use App\Http\Controllers\Family\ChildOrderController;
use Illuminate\Support\Facades\Route;

/*
 * A parent's children (docs/architecture.md §1, §7 "Parents and their
 * children", ADR 011). Isolation as in routes/classroom.php: `{child}`
 * binds only the current account's children (`BelongsToOwner`, 404 before
 * any controller code) and every route passes its policy (`->can()`). A new
 * route with `{child}` must be added to
 * tests/Feature/Classroom/TeacherIsolationTest.php, which fails otherwise.
 * A child's name never goes into a URL: `{child}` is a ULID.
 *
 * The parent area (§1 "Accounts and roles", ADR 010): an account without
 * the parent role is refused, and a page visit leads to `/start`. A home
 * game is set up in routes/play.php, in the same area.
 */
Route::middleware(['auth', 'role.chosen', 'verified', Role::middleware(Role::Parent)])->group(function () {
    Route::get('children', [ChildController::class, 'index'])->name('children.index');
    Route::post('children', [ChildController::class, 'store'])->name('children.store');
    Route::put('children/order', ChildOrderController::class)->name('children.order');

    Route::get('children/{child}', [ChildController::class, 'show'])
        ->whereUlid('child')
        ->can('view', 'child')
        ->name('children.show');
    Route::patch('children/{child}', [ChildController::class, 'update'])
        ->whereUlid('child')
        ->can('update', 'child')
        ->name('children.update');
    Route::delete('children/{child}', [ChildController::class, 'destroy'])
        ->whereUlid('child')
        ->can('delete', 'child')
        ->name('children.destroy');

    Route::put('children/{child}/learned-letters', ChildLettersController::class)
        ->whereUlid('child')
        ->can('update', 'child')
        ->name('children.learned-letters.update');
});
