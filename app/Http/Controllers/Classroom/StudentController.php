<?php

namespace App\Http\Controllers\Classroom;

use App\Domain\Classroom\Actions\AddStudents;
use App\Domain\Classroom\Actions\DeleteStudent;
use App\Domain\Classroom\Actions\UpdateStudent;
use App\Domain\Classroom\Data\AddStudentsData;
use App\Domain\Classroom\Data\StudentData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Domain\Classroom\Models\Student;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Students of a class. `store` adds the names the teacher confirmed in the
 * paste preview (`StudentPreviewController`); flash messages never carry a
 * child's name (docs/architecture.md §7).
 */
final class StudentController extends Controller
{
    public function store(Request $request, SchoolClass $class, AddStudents $addStudents): RedirectResponse
    {
        $added = $addStudents->handle($class, AddStudentsData::validate($request->all()));

        Inertia::flash('toast', [
            'type' => 'success',
            // Server-side strings take no {one}|{other} forms (those are React's).
            'message' => __('students.flash.added', ['count' => $added->count()]),
        ]);

        return to_route('classes.show', $class);
    }

    public function update(Request $request, SchoolClass $class, Student $student, UpdateStudent $updateStudent): RedirectResponse
    {
        $updateStudent->handle($student, StudentData::validate($request->all()));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('students.flash.updated')]);

        return to_route('classes.show', $class);
    }

    public function destroy(SchoolClass $class, Student $student, DeleteStudent $deleteStudent): RedirectResponse
    {
        $deleteStudent->handle($student);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('students.flash.deleted')]);

        return to_route('classes.show', $class);
    }
}
