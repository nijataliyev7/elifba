<?php

use App\Http\Controllers\Classroom\ClassArchiveController;
use App\Http\Controllers\Classroom\ClassController;
use App\Http\Controllers\Classroom\LearnedLettersController;
use App\Http\Controllers\Classroom\StudentArchiveController;
use App\Http\Controllers\Classroom\StudentController;
use App\Http\Controllers\Classroom\StudentOrderController;
use App\Http\Controllers\Classroom\StudentPreviewController;
use Illuminate\Support\Facades\Route;

/*
 * Classes and students (docs/architecture.md §7, §11). Teacher isolation:
 * `{class}` and `{student}` bind only the current teacher's rows
 * (`BelongsToTeacher`, 404 before any controller code), `{student}` only
 * inside its `{class}` (`scopeBindings()`), and every route passes its policy
 * (`->can()`). A new route with `{class}`, `{student}` or `{game}` must be
 * added to tests/Feature/Classroom/TeacherIsolationTest.php, which fails
 * otherwise.
 */
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('classes', [ClassController::class, 'index'])->name('classes.index');
    Route::post('classes', [ClassController::class, 'store'])->name('classes.store');

    Route::get('classes/{class}', [ClassController::class, 'show'])
        ->can('view', 'class')
        ->name('classes.show');
    Route::patch('classes/{class}', [ClassController::class, 'update'])
        ->can('update', 'class')
        ->name('classes.update');
    Route::delete('classes/{class}', [ClassController::class, 'destroy'])
        ->can('delete', 'class')
        ->name('classes.destroy');

    Route::post('classes/{class}/archive', [ClassArchiveController::class, 'store'])
        ->can('update', 'class')
        ->name('classes.archive');
    Route::delete('classes/{class}/archive', [ClassArchiveController::class, 'destroy'])
        ->can('update', 'class')
        ->name('classes.restore');

    Route::put('classes/{class}/learned-letters', LearnedLettersController::class)
        ->can('update', 'class')
        ->name('classes.learned-letters.update');

    // The pasted list holds surnames: POST only, never a URL (§7).
    Route::post('classes/{class}/students/preview', StudentPreviewController::class)
        ->can('update', 'class')
        ->middleware('throttle:60,1')
        ->name('classes.students.preview');
    Route::post('classes/{class}/students', [StudentController::class, 'store'])
        ->can('update', 'class')
        ->name('classes.students.store');
    Route::put('classes/{class}/students/order', StudentOrderController::class)
        ->can('update', 'class')
        ->name('classes.students.order');

    Route::scopeBindings()->group(function () {
        Route::patch('classes/{class}/students/{student}', [StudentController::class, 'update'])
            ->can('update', 'student')
            ->name('classes.students.update');
        Route::delete('classes/{class}/students/{student}', [StudentController::class, 'destroy'])
            ->can('delete', 'student')
            ->name('classes.students.destroy');
        Route::post('classes/{class}/students/{student}/archive', [StudentArchiveController::class, 'store'])
            ->can('update', 'student')
            ->name('classes.students.archive');
        Route::delete('classes/{class}/students/{student}/archive', [StudentArchiveController::class, 'destroy'])
            ->can('update', 'student')
            ->name('classes.students.restore');
    });
});
