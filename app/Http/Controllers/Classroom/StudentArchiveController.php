<?php

namespace App\Http\Controllers\Classroom;

use App\Domain\Classroom\Actions\ArchiveStudent;
use App\Domain\Classroom\Actions\RestoreStudent;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Archives a student who left the class (`POST`) or brings them back
 * (`DELETE`, blocked while an active student has the same name).
 */
final class StudentArchiveController extends Controller
{
    public function store(SchoolClass $class, Student $student, ArchiveStudent $archiveStudent): RedirectResponse
    {
        $archiveStudent->handle($student);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('students.flash.archived')]);

        return to_route('classes.show', $class);
    }

    public function destroy(SchoolClass $class, Student $student, RestoreStudent $restoreStudent): RedirectResponse
    {
        $restoreStudent->handle($student);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('students.flash.restored')]);

        return to_route('classes.show', $class);
    }
}
