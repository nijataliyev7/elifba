<?php

namespace App\Http\Controllers\Classroom;

use App\Domain\Classroom\Actions\ReorderStudents;
use App\Domain\Classroom\Data\ReorderStudentsData;
use App\Domain\Classroom\Models\SchoolClass;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Saves the order of a class's active students. The ids come in the body,
 * not the route, so `ReorderStudents` checks they are exactly this class's.
 */
final class StudentOrderController extends Controller
{
    public function __invoke(Request $request, SchoolClass $class, ReorderStudents $reorderStudents): RedirectResponse
    {
        $reorderStudents->handle($class, ReorderStudentsData::validate($request->all()));

        return to_route('classes.show', $class);
    }
}
