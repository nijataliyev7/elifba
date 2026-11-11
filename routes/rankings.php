<?php

use App\Enums\Role;
use App\Http\Controllers\Rankings\PodiumController;
use App\Http\Controllers\Rankings\RankingController;
use Illuminate\Support\Facades\Route;

/*
 * Rankings (docs/architecture.md §5, §11), read-only. Teacher isolation as
 * in routes/classroom.php: `{class}` binds only the current teacher's
 * classes (`BelongsToTeacher`, 404 before any controller code) and every
 * route passes its policy (`->can()`). Every route here is in
 * tests/Feature/Classroom/TeacherIsolationTest.php. The teacher area
 * (§1 "Accounts and roles"): rankings are a class's, never a child's.
 */
Route::middleware(['auth', 'role.chosen', 'verified', Role::middleware(Role::Teacher)])->group(function () {
    // ?period=day|week&date=YYYY-MM-DD (Rankings\Data\PeriodData).
    Route::get('classes/{class}/rankings', RankingController::class)
        ->can('view', 'class')
        ->name('classes.rankings');

    // Places 1–3 and the share card (§6), the same query.
    Route::get('classes/{class}/podium', PodiumController::class)
        ->can('view', 'class')
        ->name('classes.podium');
});
