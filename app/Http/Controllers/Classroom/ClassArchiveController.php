<?php

namespace App\Http\Controllers\Classroom;

use App\Domain\Classroom\Actions\ArchiveClass;
use App\Domain\Classroom\Actions\RestoreClass;
use App\Domain\Classroom\Models\SchoolClass;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Archives a class (`POST`) or brings it back (`DELETE`). An archived class
 * is deleted automatically after `elifba.retention_days`.
 */
final class ClassArchiveController extends Controller
{
    public function store(SchoolClass $class, ArchiveClass $archiveClass): RedirectResponse
    {
        $archiveClass->handle($class);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('classes.flash.archived')]);

        return to_route('classes.index');
    }

    public function destroy(SchoolClass $class, RestoreClass $restoreClass): RedirectResponse
    {
        $restoreClass->handle($class);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('classes.flash.restored')]);

        return to_route('classes.show', $class);
    }
}
